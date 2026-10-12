<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SudoRunner;
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

    public function test_the_setup_page_collects_the_mysql_root_password(): void
    {
        User::factory()->create();
        $this->mark('mysql');

        $this->get('/first-run')
            ->assertOk()
            ->assertSee('MySQL root password')
            ->assertDontSee('Administrator email');
    }

    public function test_the_setup_page_collects_the_administrator_account(): void
    {
        $this->mark('admin');

        $this->get('/first-run')
            ->assertOk()
            ->assertSee('Administrator email')
            ->assertDontSee('MySQL root password');
    }

    public function test_both_pending_steps_are_collected_on_one_page(): void
    {
        $this->mark('mysql', 'admin');

        $this->get('/first-run')
            ->assertOk()
            ->assertSee('MySQL root password')
            ->assertSee('Administrator email');
    }

    public function test_an_unreadable_marker_still_offers_the_mysql_password(): void
    {
        file_put_contents($this->marker, 'not json at all');

        $this->get('/first-run')
            ->assertOk()
            ->assertSee('MySQL root password');
    }

    public function test_the_setup_page_stops_being_served_once_setup_is_complete(): void
    {
        User::factory()->create();

        $this->get('/first-run')->assertRedirect('/login');
    }

    public function test_login_hands_over_to_the_setup_page_while_pending(): void
    {
        $this->mark('mysql');

        $this->get('/login')->assertRedirect(route('firstrun.index'));
    }

    public function test_the_dashboard_hands_over_to_the_setup_page_while_pending(): void
    {
        User::factory()->create();
        $this->mark('mysql');

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('firstrun.index'));
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

    public function test_submitting_hands_the_mysql_password_to_the_privileged_command(): void
    {
        $this->mark('mysql', 'admin');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(function (array $command, float $timeout, ?array $env, ?string $cwd, ?string $input): bool {
                    return $command[0] === 'sudo'
                        && in_array('panel:setup:finish', $command, true)
                        && $timeout === 120.0
                        && str_contains((string) $input, 'mysql_password":"s3cret-root');
                })
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => 'MySQL root password set.', 'stderr' => '']);
        });

        $this->from('/first-run')
            ->post('/first-run', [
                'mysql_password' => 's3cret-root',
                'email' => 'admin@example.com',
                'password' => 'super-secret-123',
                'password_confirmation' => 'super-secret-123',
            ])
            ->assertRedirect('/login');

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'is_admin' => true]);
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_a_rejected_mysql_password_keeps_setup_pending(): void
    {
        $this->mark('mysql', 'admin');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'ERROR 1045: Access denied']);
        });

        $this->from('/first-run')
            ->post('/first-run', [
                'mysql_password' => 'wrong-password',
                'email' => 'admin@example.com',
                'password' => 'super-secret-123',
                'password_confirmation' => 'super-secret-123',
            ])
            ->assertRedirect('/first-run')
            ->assertSessionHasErrors('mysql_password');

        $this->assertDatabaseCount('users', 0);
        $this->assertFileExists($this->marker);
    }

    public function test_a_sudo_denial_is_explained(): void
    {
        $this->mark('mysql');

        User::factory()->create();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->from('/first-run')
            ->post('/first-run', ['mysql_password' => 's3cret-root'])
            ->assertRedirect('/first-run')
            ->assertSessionHasErrors(['mysql_password' => SudoRunner::denialMessage()]);
    }

    public function test_validation_requires_every_pending_field(): void
    {
        $this->mark('mysql', 'admin');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->from('/first-run')
            ->post('/first-run', [])
            ->assertRedirect('/first-run')
            ->assertSessionHasErrors(['mysql_password', 'email', 'password']);
    }

    public function test_only_the_pending_steps_are_collected(): void
    {
        User::factory()->create();
        $this->mark('mysql');

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->withArgs(fn (array $command): bool => in_array('panel:setup:finish', $command, true))
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => 'MySQL root password set.', 'stderr' => '']);
        });

        $this->from('/first-run')
            ->post('/first-run', ['mysql_password' => 's3cret-root'])
            ->assertRedirect('/login')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_the_rate_limit_stops_a_password_guessing_run(): void
    {
        $this->mark('mysql');
        User::factory()->create();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'denied']);
        });

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/first-run')
                ->post('/first-run', ['mysql_password' => 'guess-'.$attempt]);
        }

        $this->from('/first-run')
            ->post('/first-run', ['mysql_password' => 'guess-again'])
            ->assertStatus(429);
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
