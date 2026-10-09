<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private string $statusFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/panel-update-web-'.uniqid();
        mkdir($this->tmp, 0755, true);
        $this->statusFile = $this->tmp.'/update-status.json';

        config(['panel.update.status_file' => $this->statusFile]);

        Http::fake([
            'api.ipify.org' => Http::response('203.0.113.10'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        foreach (Install::ofType('update')->pluck('id') as $id) {
            @unlink(storage_path('logs/installs/'.$id.'.log'));
        }

        parent::tearDown();
    }

    private function seedStatus(array $overrides = []): void
    {
        file_put_contents($this->statusFile, json_encode(array_merge([
            'update_available' => true,
            'behind' => 3,
            'current' => 'aaaa',
            'latest' => 'bbbb',
            'subject' => 'Fix the installer',
            'branch' => 'main',
            'checked_at' => now()->toIso8601String(),
            'error' => null,
        ], $overrides)));
    }

    public function test_check_requires_authentication(): void
    {
        $this->post(route('update.check'))->assertRedirect(route('login'));
    }

    public function test_run_requires_authentication(): void
    {
        $this->post(route('update.run'))->assertRedirect(route('login'));
    }

    public function test_the_banner_is_shown_when_an_update_is_pending(): void
    {
        $this->seedStatus();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Panel update available')
            ->assertSee('Fix the installer')
            ->assertSee('Update now');
    }

    public function test_the_banner_is_hidden_when_the_panel_is_current(): void
    {
        $this->seedStatus([
            'update_available' => false,
            'behind' => 0,
            'subject' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Panel update available');
    }

    public function test_the_banner_is_hidden_without_a_status_file(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Panel update available');
    }

    public function test_guests_never_see_the_banner(): void
    {
        $this->seedStatus();

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Panel update available');
    }

    public function test_check_refreshes_the_status_and_reports_a_pending_update(): void
    {
        $this->seedStatus();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'sudo'
                    && in_array('panel:update:check', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "Update available: 3 commit(s) behind.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('update.check'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Update available — 3 commit(s) behind.');
    }

    public function test_check_reports_an_up_to_date_panel(): void
    {
        $this->seedStatus(['update_available' => false, 'behind' => 0]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'sudo')
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "Panel is up to date.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('update.check'))
            ->assertSessionHas('success', 'The panel is up to date.');
    }

    public function test_check_reports_a_runner_denial(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'sudo')
                ->once()
                ->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('update.check'))
            ->assertSessionHas('error', SudoRunner::denialMessage());
    }

    public function test_check_reports_a_failed_check(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => $command[0] === 'sudo')
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'run: panel application not found']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('update.check'))
            ->assertSessionHas('error', 'Could not check for updates: run: panel application not found');
    }

    public function test_run_spawns_the_background_updater_and_redirects(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    $script = $command[2] ?? '';

                    return $command[0] === 'bash'
                        && str_contains($script, 'sudo -n /usr/local/panel/run panel:update:run')
                        && str_contains($script, '2>&1 &');
                })
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('update.run'));

        $response->assertRedirect();

        $install = Install::ofType('update')->firstOrFail();
        $this->assertSame('panel:update:run', $install->command);
        $this->assertSame('Apply panel update', $install->label);
        $this->assertSame(Install::STATUS_PENDING, $install->status);
        $this->assertSame(route('installs.show', $install), $response->headers->get('Location'));

        $this->get(route('installs.show', $install))
            ->assertOk()
            ->assertSee('Back to Dashboard');
    }

    public function test_a_second_run_reuses_the_active_job(): void
    {
        $running = Install::create([
            'type' => 'update',
            'label' => 'Apply panel update',
            'command' => 'panel:update:run',
            'status' => Install::STATUS_RUNNING,
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('update.run'))
            ->assertRedirect(route('installs.show', $running));

        $this->assertSame(1, Install::ofType('update')->count());
    }
}
