<?php

namespace Tests\Feature;

use App\Models\Install;
use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PmaInstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.phpmyadmin.dir' => sys_get_temp_dir().'/panel-pma-'.uniqid(),
            'panel.domain' => 'panel.example.com',
        ]);
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('pma.index'))->assertRedirect(route('login'));
    }

    public function test_it_shows_the_installer_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('pma.index'))
            ->assertOk()
            ->assertSee('phpMyAdmin')
            ->assertSee('pma.panel.example.com')
            ->assertSee('Install phpMyAdmin');
    }

    public function test_install_spawns_the_background_installer_and_redirects(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command): bool {
                    $script = $command[2] ?? '';

                    return $command[0] === 'bash'
                        && str_contains($script, 'sudo -n /usr/local/panel/run panel:pma:install')
                        && str_contains($script, '2>&1 &');
                })
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('pma.install'));

        $response->assertRedirect();

        $install = Install::ofType('pma')->firstOrFail();
        $this->assertSame('panel:pma:install', $install->command);
        $this->assertSame(Install::STATUS_PENDING, $install->status);
        $this->assertSame(route('installs.show', $install), $response->headers->get('Location'));

        $this->get(route('installs.show', $install))->assertOk();
    }

    public function test_a_second_install_uses_the_active_job_instead_of_spawning(): void
    {
        $running = Install::create([
            'type' => 'pma',
            'label' => 'Install phpMyAdmin',
            'command' => 'panel:pma:install',
            'status' => Install::STATUS_RUNNING,
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('pma.install'))
            ->assertRedirect(route('installs.show', $running));

        $this->assertSame(1, Install::ofType('pma')->count());
    }

    public function test_uninstall_success_flashes_a_confirmation(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:pma:uninstall', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => "phpMyAdmin uninstalled.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('pma.index'))
            ->delete(route('pma.uninstall'))
            ->assertRedirect(route('pma.index'))
            ->assertSessionHas('success');
    }

    public function test_uninstall_denial_is_reported(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('pma.index'))
            ->delete(route('pma.uninstall'))
            ->assertSessionHas('error', SudoRunner::denialMessage());
    }

    /**
     * A `--version` option collides with Symfony's global version flag and
     * makes the whole command fail to register — the install button would
     * then spawn a command that cannot even start.
     */
    public function test_the_installer_command_is_registered(): void
    {
        $this->artisan('panel:pma:install', ['id' => 999999])
            ->expectsOutputToContain('Install record not found.')
            ->assertFailed();
    }
}
