<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.ipify.org' => Http::response('203.0.113.10'),
        ]);
    }

    public function test_guest_cannot_see_dashboard(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_dashboard_renders_for_admin(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('services', false)
            ->assertSee('203.0.113.10');
    }

    public function test_dashboard_always_reports_the_caddy_service(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Caddy')
            ->assertSee('Restart Caddy');
    }

    public function test_restart_requires_authentication(): void
    {
        $this->post('/services/restart', ['unit' => 'caddy'])->assertRedirect('/login');
    }

    public function test_restart_rejects_unmanaged_units(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from('/')
            ->post('/services/restart', ['unit' => 'caddy; rm -rf /'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('unit');
    }

    public function test_restart_passes_managed_unit_to_sudo_runner(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->with(['sudo', '-n', SudoRunner::WRAPPER, 'panel:service:restart', 'caddy'], 120.0)
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => 'caddy is active.', 'stderr' => '']);

            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->actingAs(User::factory()->create())
            ->from('/')
            ->post('/services/restart', ['unit' => 'caddy'])
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Restarted caddy.');
    }

    public function test_restart_shows_sudo_hint_when_runner_is_not_installed(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->with(['sudo', '-n', SudoRunner::WRAPPER, 'panel:service:restart', 'caddy'], 120.0)
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'sudo: a password is required']);

            $mock->shouldReceive('run')->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])->byDefault();
        });

        $this->actingAs(User::factory()->create())
            ->from('/')
            ->post('/services/restart', ['unit' => 'caddy'])
            ->assertRedirect('/')
            ->assertSessionHas('error', SudoRunner::denialMessage());
    }
}
