<?php

namespace Tests\Feature;

use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelServiceRestartTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_services_outside_the_allowlist(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:service:restart', ['service' => 'ssh'])
            ->expectsOutputToContain('Service not allowed')
            ->assertFailed();
    }

    public function test_it_rejects_shell_injection_attempts(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:service:restart', ['service' => 'caddy; touch /tmp/pwned'])
            ->assertFailed();
    }

    public function test_it_restarts_an_allowed_service(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->with(['systemctl', 'restart', 'caddy'], 120)
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => '']);

            $mock->shouldReceive('run')
                ->with(['systemctl', 'is-active', 'caddy'])
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "active\n", 'stderr' => '']);
        });

        $this->artisan('panel:service:restart', ['service' => 'caddy'])
            ->expectsOutputToContain('caddy is active')
            ->assertSuccessful();
    }

    public function test_it_fails_when_systemctl_reports_an_error(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->with(['systemctl', 'restart', 'php8.3-fpm'], 120)
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'Unit php8.3-fpm.service not found.']);

            $mock->shouldReceive('run')
                ->with(['journalctl', '-u', 'php8.3-fpm', '-n', '12', '--no-pager', '-o', 'cat'], 20.0)
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "config test failed\n", 'stderr' => '']);
        });

        $this->artisan('panel:service:restart', ['service' => 'php8.3-fpm'])
            ->expectsOutputToContain('Unit php8.3-fpm.service not found')
            ->expectsOutputToContain('config test failed')
            ->assertFailed();
    }

    public function test_it_shows_mysqls_own_error_log_when_the_restart_fails(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->with(['systemctl', 'restart', 'mysql'], 120)
                ->once()
                ->andReturn([
                    'exit' => 1,
                    'stdout' => '',
                    'stderr' => 'Job for mysql.service failed because the control process exited with error code.',
                ]);

            $mock->shouldReceive('run')
                ->with(['journalctl', '-u', 'mysql', '-n', '12', '--no-pager', '-o', 'cat'], 20.0)
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "Starting MySQL database server...\n", 'stderr' => '']);

            $mock->shouldReceive('run')
                ->with(['tail', '-n', '12', '/var/log/mysql/error.log'], 20.0)
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "[ERROR] unknown variable 'bind-adres=0.0.0.0'\n", 'stderr' => '']);
        });

        $this->artisan('panel:service:restart', ['service' => 'mysql'])
            ->expectsOutputToContain('Job for mysql.service failed')
            ->expectsOutputToContain("unknown variable 'bind-adres=0.0.0.0'")
            ->assertFailed();
    }
}
