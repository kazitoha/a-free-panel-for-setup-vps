<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class FirstRunTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = sys_get_temp_dir().'/panel-first-run-'.uniqid().'.json';
        config(['panel.setup.marker' => $this->marker]);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);

        parent::tearDown();
    }

    public function test_the_setup_page_collects_the_administrator_account(): void
    {
        $this->mark('admin');

        $this->get('/first-run')
            ->assertOk()
            ->assertSee('Administrator email')
            ->assertSee('Administrator password');
    }

    public function test_an_unreadable_marker_still_offers_the_administrator_account(): void
    {
        file_put_contents($this->marker, 'not json at all');

        $this->get('/first-run')
            ->assertOk()
            ->assertSee('Administrator email');
    }

    public function test_the_setup_page_stops_being_served_once_setup_is_complete(): void
    {
        User::factory()->create();

        $this->get('/first-run')->assertRedirect('/login');
    }

    public function test_a_leftover_installer_marker_heals_once_an_administrator_exists(): void
    {
        User::factory()->create();
        $this->mark('mysql');

        $this->get('/first-run')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_login_hands_over_to_the_setup_page_while_pending(): void
    {
        $this->mark('admin');

        $this->get('/login')->assertRedirect(route('firstrun.index'));
    }

    public function test_the_dashboard_hands_over_to_the_setup_page_while_pending(): void
    {
        $this->mark('admin');

        $this->get('/')
            ->assertRedirect(route('login'));

        $this->get(route('login'))->assertRedirect(route('firstrun.index'));
    }

    public function test_submitting_creates_the_administrator_and_completes_setup(): void
    {
        $this->mark('admin');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->from('/first-run')
            ->post('/first-run', [
                'email' => 'admin@example.com',
                'password' => 'super-secret-123',
                'password_confirmation' => 'super-secret-123',
            ])
            ->assertRedirect('/login')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'is_admin' => true]);
        $this->assertFileDoesNotExist($this->marker);
        $this->get('/first-run')->assertRedirect('/login');
    }

    public function test_submitting_when_setup_is_not_pending_does_nothing(): void
    {
        User::factory()->create();

        $this->from('/first-run')
            ->post('/first-run', [
                'email' => 'second@example.com',
                'password' => 'super-secret-123',
                'password_confirmation' => 'super-secret-123',
            ])
            ->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_validation_requires_the_administrator_fields(): void
    {
        $this->mark('admin');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->from('/first-run')
            ->post('/first-run', [])
            ->assertRedirect('/first-run')
            ->assertSessionHasErrors(['email', 'password']);
    }

    public function test_the_rate_limit_stops_a_password_guessing_run(): void
    {
        $this->mark('admin');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/first-run')
                ->post('/first-run', [
                    'email' => 'guess-'.$attempt,
                    'password' => '',
                    'password_confirmation' => '',
                ]);
        }

        $this->from('/first-run')
            ->post('/first-run', [
                'email' => 'guess-again',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertStatus(429);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Record the steps install-panel.sh could not do itself.
     */
    private function mark(string ...$steps): void
    {
        file_put_contents($this->marker, json_encode([
            'steps' => $steps,
            'created_at' => now()->toIso8601String(),
        ]));
    }
}
