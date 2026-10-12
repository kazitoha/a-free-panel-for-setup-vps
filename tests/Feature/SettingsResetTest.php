<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Models\User;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SettingsResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['panel.domain' => 'panel.example.com']);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
    }

    public function test_it_shows_the_danger_zone(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('danger zone')
            ->assertSee('panel.example.com')
            ->assertSee('Reset the panel');
    }

    public function test_reset_spawns_the_background_installer_and_redirects(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    $script = $command[2] ?? '';

                    return $command[0] === 'bash'
                        && str_contains($script, 'sudo -n /usr/local/panel/run panel:reset')
                        && str_contains($script, '2>&1 &');
                })
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.reset'));

        $response->assertRedirect();

        $install = Install::ofType('reset')->firstOrFail();
        $this->assertSame('panel:reset', $install->command);
        $this->assertSame(Install::STATUS_PENDING, $install->status);
        $this->assertSame(route('installs.show', $install), $response->headers->get('Location'));

        $this->get(route('installs.show', $install))->assertOk();
    }

    public function test_a_second_reset_uses_the_active_job(): void
    {
        $running = Install::create([
            'type' => 'reset',
            'label' => 'Reset the panel',
            'command' => 'panel:reset',
            'status' => Install::STATUS_RUNNING,
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('settings.reset'))
            ->assertRedirect(route('installs.show', $running));

        $this->assertSame(1, Install::ofType('reset')->count());
    }
}
