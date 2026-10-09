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
        });

        $this->artisan('panel:service:restart', ['service' => 'php8.3-fpm'])
            ->expectsOutputToContain('Unit php8.3-fpm.service not found')
            ->assertFailed();
    }
}
