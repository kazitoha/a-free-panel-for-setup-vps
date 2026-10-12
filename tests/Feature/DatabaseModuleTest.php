<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use App\Support\SecretInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class DatabaseModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('databases.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_panel_databases_with_live_sizes(): void
    {
        Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:database:list', $command, true))
                ->andReturn([
                    'exit' => 0,
                    'stdout' => '[{"name":"myapp","size_kb":24,"tables":3}]',
                    'stderr' => '',
                ]);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('databases.index'))
            ->assertOk()
            ->assertSee('myapp')
            ->assertSee('24 KB')
            ->assertSee('3')
            ->assertDontSee('secret1234');
    }

    public function test_index_still_renders_when_mysql_is_unreachable(): void
    {
        Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'ERROR 2002']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('databases.index'))
            ->assertOk()
            ->assertSee('myapp')
            ->assertSee('Could not reach MySQL');
    }

    public function test_store_creates_the_database_and_encodes_the_password(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command, float $timeout, ?array $env, ?string $cwd, ?string $input): bool => $command === [
                    'sudo', '-n', '/usr/local/panel/run',
                    'panel:database:create', '--password-stdin', 'myapp', 'myapp',
                ] && $timeout === 60.0 && $input === "secret1234\n")
                ->andReturn(['exit' => 0, 'stdout' => "Created myapp.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->post(route('databases.store'), [
                'name' => 'MyApp',
                'username' => 'MyApp',
                'password' => 'secret1234',
            ])
            ->assertRedirect(route('databases.index'))
            ->assertSessionHas('success');

        $row = Database::firstOrFail();
        $this->assertSame('myapp', $row->name);
        $this->assertSame('myapp', $row->username);
        $this->assertSame('secret1234', $row->password);
        $this->assertNotSame('secret1234', DB::table('databases')->value('password'));
    }

    public function test_store_rejects_an_invalid_name_without_touching_the_host(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->post(route('databases.store'), [
                'name' => '1bad',
                'username' => 'myapp',
                'password' => 'secret1234',
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('databases', 0);
    }

    public function test_store_rejects_a_reserved_name(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->post(route('databases.store'), [
                'name' => 'pdns',
                'username' => 'myapp',
                'password' => 'secret1234',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_store_rejects_a_duplicate_panel_database(): void
    {
        Database::create(['name' => 'myapp', 'username' => 'other', 'password' => 'secret1234']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->post(route('databases.store'), [
                'name' => 'myapp',
                'username' => 'second',
                'password' => 'secret1234',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_store_reports_a_sudo_denial(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->post(route('databases.store'), [
                'name' => 'myapp',
                'username' => 'myapp',
                'password' => 'secret1234',
            ])
            ->assertSessionHas('error', SudoRunner::denialMessage());

        $this->assertDatabaseCount('databases', 0);
    }

    public function test_rotate_password_updates_the_stored_secret(): void
    {
        $database = Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command, float $timeout, ?array $env, ?string $cwd, ?string $input): bool => $command === [
                    'sudo', '-n', '/usr/local/panel/run',
                    'panel:database:user-password', '--password-stdin', 'myapp',
                ] && $timeout === 60.0 && $input === "newsecret5678\n")
                ->andReturn(['exit' => 0, 'stdout' => "Password updated.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->post(route('databases.password', $database), ['password' => 'newsecret5678'])
            ->assertRedirect(route('databases.index'))
            ->assertSessionHas('success');

        $this->assertSame('newsecret5678', $database->fresh()->password);
    }

    public function test_rotate_password_rejects_a_weak_password(): void
    {
        $database = Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->post(route('databases.password', $database), ['password' => 'short'])
            ->assertSessionHasErrors('password');

        $this->assertSame('secret1234', $database->fresh()->password);
    }

    public function test_destroy_drops_the_database_and_the_record(): void
    {
        $database = Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with([
                    'sudo', '-n', '/usr/local/panel/run',
                    'panel:database:drop', 'myapp', 'myapp',
                ], 60.0)
                ->andReturn(['exit' => 0, 'stdout' => "Dropped myapp.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.index'))
            ->delete(route('databases.destroy', $database))
            ->assertRedirect(route('databases.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('databases', 0);
    }

    public function test_destroy_keeps_the_record_when_the_command_fails(): void
    {
        $database = Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'mysqld is down']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('databases.destroy', $database))
            ->delete(route('databases.destroy', $database))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('databases', 1);
    }

    public function test_credentials_endpoint_returns_the_stored_password(): void
    {
        $database = Database::create(['name' => 'myapp', 'username' => 'myapp', 'password' => 'secret1234']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('databases.credentials', $database))
            ->assertOk()
            ->assertJson(['username' => 'myapp', 'password' => 'secret1234']);

        $this->assertSame('secret1234', $response->json('password'));
    }

    public function test_create_command_rejects_an_invalid_name(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->fakeSecret('secret1234');

        $this->artisan('panel:database:create', [
            'name' => 'bad name;',
            'username' => 'myapp',
            '--password-stdin' => true,
        ])->assertFailed();
    }

    public function test_create_command_rejects_a_metacharacter_password(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->fakeSecret('secret$(id)');

        $this->artisan('panel:database:create', [
            'name' => 'myapp',
            'username' => 'myapp',
            '--password-stdin' => true,
        ])->assertFailed();
    }

    public function test_create_command_requires_the_password_on_standard_input(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:database:create', [
            'name' => 'myapp',
            'username' => 'myapp',
        ])->expectsOutputToContain('--password-stdin')->assertFailed();
    }

    public function test_create_command_creates_the_database_and_user_on_both_hosts(): void
    {
        $commands = [];

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$commands): void {
            $mock->shouldReceive('run')
                ->times(3)
                ->withArgs(function (array $command, float $timeout, ?array $env): bool {
                    return ($command[0] ?? '') === 'mysql'
                        && $env === ['MYSQL_PWD' => (string) config('panel.mysql.root_password')];
                })
                ->andReturnUsing(function (array $command) use (&$commands) {
                    $commands[] = $command;

                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                });
        });

        $this->fakeSecret('secret1234');

        $this->artisan('panel:database:create', [
            'name' => 'myapp',
            'username' => 'myapp',
            '--password-stdin' => true,
        ])->assertSuccessful();

        $this->assertCount(3, $commands);
        $sql = (string) ($commands[2][count($commands[2]) - 1] ?? '');

        $this->assertStringContainsString('CREATE DATABASE `myapp`', $sql);
        $this->assertStringContainsString("'myapp'@'localhost'", $sql);
        $this->assertStringContainsString("'myapp'@'127.0.0.1'", $sql);
        $this->assertStringContainsString('GRANT ALL PRIVILEGES ON `myapp`.*', $sql);
    }

    public function test_create_command_refuses_an_existing_database(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(['exit' => 0, 'stdout' => "myapp\n", 'stderr' => '']);
        });

        $this->fakeSecret('secret1234');

        $this->artisan('panel:database:create', [
            'name' => 'myapp',
            'username' => 'myapp',
            '--password-stdin' => true,
        ])->expectsOutputToContain('already exists')->assertFailed();
    }

    public function test_drop_command_refuses_reserved_names(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:database:drop', [
            'name' => 'pdns',
            'username' => 'pdns_user',
        ])->assertFailed();
    }

    public function test_list_command_formats_rows_as_json(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => $command[0] === 'mysql')
                ->andReturn(['exit' => 0, 'stdout' => "myapp\t24\t3\n", 'stderr' => '']);
        });

        $this->artisan('panel:database:list')
            ->expectsOutputToContain('{"name":"myapp","size_kb":24,"tables":3}')
            ->assertSuccessful();
    }

    public function test_list_command_reports_an_unreachable_mysql(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => "Can't connect to local MySQL server"]);
        });

        $this->artisan('panel:database:list')
            ->expectsOutputToContain('MySQL is not reachable')
            ->assertFailed();
    }

    /**
     * Supply the password the command would otherwise read from the real stdin.
     */
    private function fakeSecret(string $value): void
    {
        $this->mock(SecretInput::class, function (MockInterface $mock) use ($value): void {
            $mock->shouldReceive('read')->andReturn($value);
        });
    }
}
