<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SudoRunner;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class LogsModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $caddyfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caddyfile = sys_get_temp_dir().'/panel-Caddyfile-'.uniqid();

        config([
            'panel.caddyfile' => $this->caddyfile,
            'panel.domain' => 'panel.example.com',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->caddyfile);

        parent::tearDown();
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('logs.index'))->assertRedirect(route('login'));
    }

    public function test_journal_view_renders_service_output(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:logs:journal', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => "2026-10-09 caddy started\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('caddy started')
            ->assertSee('Journal');
    }

    public function test_journal_view_falls_back_to_an_allowlisted_unit_and_clamps_lines(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:logs:journal', $command, true)
                    && in_array('caddy', $command, true)
                    && in_array('--lines=1000', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => "line\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('logs.index', ['source' => 'journal', 'unit' => '../../etc/passwd', 'lines' => 99999]))
            ->assertOk()
            ->assertSee('line');
    }

    public function test_journal_view_surfaces_command_errors(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'Failed to add filter']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Failed to add filter');
    }

    public function test_app_logs_tail_the_newest_file(): void
    {
        @mkdir(storage_path('logs'), 0755, true);
        $file = storage_path('logs/module-logs-test.log');
        file_put_contents($file, "LOGTAIL-MARKER line one\nline two\n");

        try {
            $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
                $mock->shouldReceive('run')->never();
            });

            $this->actingAs(User::factory()->create())
                ->get(route('logs.index', ['source' => 'app']))
                ->assertOk()
                ->assertSee('LOGTAIL-MARKER')
                ->assertSee('module-logs-test.log');
        } finally {
            @unlink($file);
        }
    }

    public function test_app_logs_reject_a_path_traversal_file_parameter(): void
    {
        @mkdir(storage_path('logs'), 0755, true);
        $file = storage_path('logs/module-logs-traversal.log');
        file_put_contents($file, "SAFE-LOG-CONTENT\n");

        try {
            $this->actingAs(User::factory()->create())
                ->get(route('logs.index', ['source' => 'app', 'file' => '../../.env']))
                ->assertOk()
                ->assertSee('SAFE-LOG-CONTENT')
                ->assertDontSee('APP_KEY=');
        } finally {
            @unlink($file);
        }
    }

    public function test_caddyfile_view_renders_the_current_configuration(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:caddyfile:read', $command, true))
                ->andReturn(['exit' => 0, 'stdout' => "example.com {\n}\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('logs.index', ['source' => 'caddyfile']))
            ->assertOk()
            ->assertSee('example.com {', false);
    }

    public function test_saving_the_caddyfile_stages_validates_and_cleans_up(): void
    {
        $captured = null;

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (array $command) use (&$captured): bool {
                    if (in_array('panel:caddyfile:write', $command, true)) {
                        $captured = (string) end($command);
                    }

                    return in_array('panel:caddyfile:write', $command, true);
                })
                ->andReturn(['exit' => 0, 'stdout' => "Caddyfile saved and Caddy reloaded.\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('logs.index', ['source' => 'caddyfile']))
            ->post(route('logs.caddyfile'), ['content' => "example.com {\n\tencode gzip\n}\n"])
            ->assertRedirect(route('logs.index', ['source' => 'caddyfile']))
            ->assertSessionHas('success');

        $this->assertIsString($captured);
        $this->assertFileDoesNotExist($captured);
    }

    public function test_saving_an_oversized_caddyfile_never_reaches_the_host(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('logs.index', ['source' => 'caddyfile']))
            ->post(route('logs.caddyfile'), ['content' => str_repeat('a', 200_001)])
            ->assertSessionHas('error');
    }

    public function test_saving_reports_a_sudo_denial(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('logs.index', ['source' => 'caddyfile']))
            ->post(route('logs.caddyfile'), ['content' => "{}\n"])
            ->assertSessionHas('error', SudoRunner::denialMessage());
    }

    public function test_journal_command_rejects_units_outside_the_allowlist(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:logs:journal', ['unit' => 'ssh'])
            ->expectsOutputToContain('Service not allowed')
            ->assertFailed();
    }

    public function test_journal_command_prints_entries(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(['journalctl', '-u', 'caddy', '-n', '200', '--no-pager', '-o', 'short-iso'], 30.0)
                ->andReturn(['exit' => 0, 'stdout' => "entry one\nentry two\n", 'stderr' => '']);
        });

        $this->artisan('panel:logs:journal', ['unit' => 'caddy'])
            ->expectsOutputToContain('entry one')
            ->assertSuccessful();
    }

    public function test_caddyfile_read_prints_the_file(): void
    {
        file_put_contents($this->caddyfile, "site example.com {\n}\n");

        $this->artisan('panel:caddyfile:read')
            ->expectsOutputToContain('site example.com')
            ->assertSuccessful();
    }

    public function test_caddyfile_write_restores_the_previous_file_when_validation_fails(): void
    {
        file_put_contents($this->caddyfile, "old-config\n");

        $staged = storage_path('app/caddyfile-stage-'.uniqid());
        file_put_contents($staged, "broken-config\n");

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(['caddy', 'validate', '--config', $this->caddyfile], 30)
                ->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'adapter caddyfile: unexpected token']);
        });

        try {
            $this->artisan('panel:caddyfile:write', ['file' => $staged])
                ->expectsOutputToContain('previous Caddyfile restored')
                ->assertFailed();
        } finally {
            @unlink($staged);
        }

        $this->assertSame("old-config\n", file_get_contents($this->caddyfile));
    }

    public function test_caddyfile_write_saves_and_reloads_on_success(): void
    {
        file_put_contents($this->caddyfile, "old-config\n");

        $staged = storage_path('app/caddyfile-stage-'.uniqid());
        file_put_contents($staged, "new-config\n");

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(['caddy', 'validate', '--config', $this->caddyfile], 30)
                ->andReturn(['exit' => 0, 'stdout' => 'Valid configuration', 'stderr' => ''])
                ->ordered();
            $mock->shouldReceive('run')
                ->once()
                ->with(['systemctl', 'reload', 'caddy'], 30)
                ->andReturn(['exit' => 0, 'stdout' => '', 'stderr' => ''])
                ->ordered();
        });

        try {
            $this->artisan('panel:caddyfile:write', ['file' => $staged])
                ->expectsOutputToContain('saved and Caddy reloaded')
                ->assertSuccessful();
        } finally {
            @unlink($staged);
        }

        $this->assertSame("new-config\n", file_get_contents($this->caddyfile));
    }

    public function test_caddyfile_write_refuses_files_outside_storage_app(): void
    {
        $outside = sys_get_temp_dir().'/panel-outside-'.uniqid();
        file_put_contents($outside, "sneaky\n");

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        try {
            $this->artisan('panel:caddyfile:write', ['file' => $outside])
                ->expectsOutputToContain('storage/app')
                ->assertFailed();
        } finally {
            @unlink($outside);
        }
    }
}
