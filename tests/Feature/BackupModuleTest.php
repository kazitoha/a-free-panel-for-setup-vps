<?php

namespace Tests\Feature;

use App\Models\BackupSchedule;
use App\Models\Database;
use App\Models\Project;
use App\Models\User;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class BackupModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/panel-backups-'.uniqid();
        mkdir($this->tmp, 0777, true);

        config([
            'panel.backup.dir' => $this->tmp.'/backups',
            'panel.scheduler_lock' => $this->tmp.'/scheduler.lock',
            'panel.cron_file' => $this->tmp.'/cron.d-panel',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    private function makeDatabase(array $overrides = []): Database
    {
        return Database::create(array_merge([
            'name' => 'myapp',
            'username' => 'myapp',
            'password' => 'secret1234',
        ], $overrides));
    }

    private function makeSchedule(Database|Project $target, array $overrides = []): BackupSchedule
    {
        $schedule = new BackupSchedule(array_merge([
            'target_type' => $target instanceof Database ? BackupSchedule::TYPE_DATABASE : BackupSchedule::TYPE_PROJECT,
            'target_id' => $target->id,
            'times_per_day' => 1,
            'retention_days' => 7,
            'enabled' => true,
        ], $overrides));

        if (! array_key_exists('next_run_at', $overrides)) {
            $schedule->next_run_at = $schedule->computeNextRunAt();
        }

        $schedule->save();

        return $schedule;
    }

    /**
     * Fake the mysqldump → gzip → gzip -t pipeline by creating the files the
     * real binaries would leave behind.
     */
    private function mockBackupPipeline(MockInterface $mock): void
    {
        $mock->shouldReceive('run')->andReturnUsing(function (array $command) {
            $first = $command[0] ?? '';

            if ($first === 'mysqldump') {
                foreach ($command as $arg) {
                    if (str_starts_with($arg, '--result-file=')) {
                        file_put_contents(substr($arg, strlen('--result-file=')), "DUMP\n");
                    }
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }

            if ($first === 'gzip') {
                if (($command[1] ?? '') === '-f') {
                    file_put_contents($command[2].'.gz', "GZ\n");
                    @unlink($command[2]);
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }

            if ($first === 'tar') {
                if (($command[1] ?? '') === 'czf') {
                    file_put_contents($command[2], "TAR\n");
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }

            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        });
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(route('backups.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_schedules_and_archives(): void
    {
        $database = $this->makeDatabase();
        $this->makeSchedule($database);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => in_array('panel:backup:list', $command, true))
                ->andReturn([
                    'exit' => 0,
                    'stdout' => json_encode([
                        'databases' => [[
                            'file' => 'myapp-20261009-030000.sql.gz',
                            'size' => 1048576,
                            'mtime' => 1760000000,
                        ]],
                        'projects' => [],
                    ]),
                    'stderr' => '',
                ]);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('backups.index'))
            ->assertOk()
            ->assertSee('myapp')
            ->assertSee('myapp-20261009-030000.sql.gz')
            ->assertSee('1.0 MB');
    }

    public function test_index_renders_when_the_runner_is_unavailable(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 77, 'stdout' => '', 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('backups.index'))
            ->assertOk()
            ->assertSee('Could not read the backup directory');
    }

    public function test_store_creates_a_schedule_with_a_next_run_time(): void
    {
        $database = $this->makeDatabase();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->post(route('backups.store'), [
                'target' => BackupSchedule::TYPE_DATABASE.':'.$database->id,
                'times_per_day' => 3,
                'retention_days' => 14,
            ])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('success');

        $schedule = BackupSchedule::firstOrFail();
        $this->assertSame(3, $schedule->times_per_day);
        $this->assertSame(14, $schedule->retention_days);
        $this->assertTrue($schedule->enabled);
        $this->assertNotNull($schedule->next_run_at);
    }

    public function test_store_rejects_a_target_that_already_has_a_schedule(): void
    {
        $database = $this->makeDatabase();
        $this->makeSchedule($database);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->post(route('backups.store'), [
                'target' => BackupSchedule::TYPE_DATABASE.':'.$database->id,
                'times_per_day' => 1,
                'retention_days' => 7,
            ])
            ->assertSessionHasErrors('target');

        $this->assertDatabaseCount('backup_schedules', 1);
    }

    public function test_store_rejects_an_unknown_target(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->post(route('backups.store'), [
                'target' => 'database:9999',
                'times_per_day' => 1,
                'retention_days' => 7,
            ])
            ->assertSessionHasErrors('target');

        $this->assertDatabaseCount('backup_schedules', 0);
    }

    public function test_run_now_asks_the_runner_and_reports_success(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database);

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($schedule): void {
            $mock->shouldReceive('run')
                ->once()
                ->with([
                    'sudo', '-n', '/usr/local/panel/run',
                    'panel:backup:run', (string) $schedule->id,
                ], 600.0)
                ->andReturn(['exit' => 0, 'stdout' => "Saved /var/backups/panel/databases/myapp-x.sql.gz (2.5 MB)\n", 'stderr' => '']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->post(route('backups.run', $schedule))
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('success', 'Saved /var/backups/panel/databases/myapp-x.sql.gz (2.5 MB)');
    }

    public function test_run_now_reports_runner_failure(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->andReturn(['exit' => 1, 'stdout' => '', 'stderr' => 'mysqldump died']);
        });

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->post(route('backups.run', $schedule))
            ->assertSessionHas('error');

        $this->assertNull($schedule->fresh()->last_status);
    }

    public function test_toggle_pauses_and_resumes_a_schedule(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->post(route('backups.toggle', $schedule))
            ->assertSessionHas('success');

        $schedule->refresh();
        $this->assertFalse($schedule->enabled);
        $this->assertNull($schedule->next_run_at);

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->post(route('backups.toggle', $schedule))
            ->assertSessionHas('success');

        $schedule->refresh();
        $this->assertTrue($schedule->enabled);
        $this->assertNotNull($schedule->next_run_at);
    }

    public function test_destroy_removes_the_schedule(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->actingAs(User::factory()->create())
            ->from(route('backups.index'))
            ->delete(route('backups.destroy', $schedule))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('backup_schedules', 0);
    }

    public function test_backup_database_command_creates_and_verifies_the_archive(): void
    {
        $database = $this->makeDatabase();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $this->mockBackupPipeline($mock);
        });

        $this->artisan('panel:backup:database', ['database' => $database->id])
            ->expectsOutputToContain('Saved')
            ->assertSuccessful();

        $files = glob($this->tmp.'/backups/databases/myapp-*.sql.gz');
        $this->assertNotFalse($files);
        $this->assertCount(1, $files);
    }

    public function test_backup_database_command_fails_when_mysqldump_fails(): void
    {
        $database = $this->makeDatabase();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => ($command[0] ?? '') === 'mysqldump')
                ->andReturn(['exit' => 2, 'stdout' => '', 'stderr' => 'Got error: 1045']);
        });

        $this->artisan('panel:backup:database', ['database' => $database->id])
            ->expectsOutputToContain('Got error: 1045')
            ->assertFailed();

        $files = glob($this->tmp.'/backups/databases/*');
        $this->assertSame([], $files ?: []);
    }

    public function test_backup_database_command_rejects_an_unknown_database(): void
    {
        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:backup:database', ['database' => 9999])
            ->expectsOutputToContain('Database not found')
            ->assertFailed();
    }

    public function test_backup_project_command_archives_with_excludes(): void
    {
        $wwwDir = sys_get_temp_dir().'/panel-www-'.uniqid();
        mkdir($wwwDir.'/myapp', 0755, true);
        file_put_contents($wwwDir.'/myapp/index.php', '<?php');

        $project = Project::create([
            'name' => 'myapp',
            'repo_url' => 'https://github.com/user/myapp.git',
            'branch' => 'main',
            'domain' => 'myapp.example.com',
            'php_version' => '8.3',
            'path' => $wwwDir.'/myapp',
        ]);

        $commands = [];

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use (&$commands): void {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use (&$commands) {
                $commands[] = $command;

                if (($command[1] ?? '') === 'czf') {
                    file_put_contents($command[2], "TAR\n");
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            });
        });

        $this->artisan('panel:backup:project', ['project' => $project->id])
            ->expectsOutputToContain('Saved')
            ->assertSuccessful();

        $files = glob($this->tmp.'/backups/projects/myapp-*.tar.gz');
        $this->assertCount(1, $files ?: []);

        $this->assertContains('--exclude=./vendor', $commands[0]);
        $this->assertContains('--exclude=./node_modules', $commands[0]);
        $this->assertContains($wwwDir.'/myapp', $commands[0]);

        File::deleteDirectory($wwwDir);
    }

    public function test_backup_project_command_fails_when_the_directory_is_missing(): void
    {
        $project = Project::create([
            'name' => 'ghost',
            'repo_url' => 'https://github.com/user/ghost.git',
            'branch' => 'main',
            'domain' => 'ghost.example.com',
            'php_version' => '8.3',
            'path' => '/nonexistent/path/ghost',
        ]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:backup:project', ['project' => $project->id])
            ->expectsOutputToContain('Project directory not found')
            ->assertFailed();
    }

    public function test_retention_prunes_archives_older_than_the_window(): void
    {
        $database = $this->makeDatabase();

        $dir = $this->tmp.'/backups/databases';
        mkdir($dir, 0777, true);
        $old = $dir.'/myapp-20200101-000000.sql.gz';
        file_put_contents($old, 'OLD');
        touch($old, now()->subDays(30)->getTimestamp());

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $this->mockBackupPipeline($mock);
        });

        $this->artisan('panel:backup:database', [
            'database' => $database->id,
            '--retention' => 7,
        ])->assertSuccessful();

        $files = glob($dir.'/myapp-*.sql.gz');
        $this->assertCount(1, $files ?: []);
        $this->assertStringNotContainsString('20200101', (string) ($files[0] ?? ''));
    }

    public function test_scheduler_tick_runs_due_schedules_and_advances_next_run(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database, ['next_run_at' => now()->subMinutes(5)]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $this->mockBackupPipeline($mock);
        });

        $this->artisan('panel:scheduler:tick')->assertSuccessful();

        $schedule->refresh();
        $this->assertSame('success', $schedule->last_status);
        $this->assertNotNull($schedule->last_path);
        $this->assertNotNull($schedule->last_run_at);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
    }

    public function test_scheduler_tick_skips_schedules_that_are_not_due(): void
    {
        $database = $this->makeDatabase();
        $this->makeSchedule($database, ['next_run_at' => now()->addHours(3)]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:scheduler:tick')
            ->expectsOutputToContain('No schedules due')
            ->assertSuccessful();

        $this->assertNull(BackupSchedule::firstOrFail()->last_run_at);
    }

    public function test_scheduler_tick_skips_when_the_lock_is_held(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database, ['next_run_at' => now()->subMinutes(5)]);
        $dueAt = $schedule->next_run_at->getTimestamp();

        $lock = fopen(config('panel.scheduler_lock'), 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
                $mock->shouldReceive('run')->never();
            });

            $this->artisan('panel:scheduler:tick')->assertSuccessful();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $schedule->refresh();
        $this->assertNull($schedule->last_run_at);
        $this->assertSame($dueAt, $schedule->next_run_at->getTimestamp());
    }

    public function test_scheduler_tick_disables_a_schedule_whose_target_is_gone(): void
    {
        $orphan = new BackupSchedule([
            'target_type' => BackupSchedule::TYPE_DATABASE,
            'target_id' => 99999,
            'times_per_day' => 1,
            'retention_days' => 7,
            'enabled' => true,
            'next_run_at' => now()->subMinutes(5),
        ]);
        $orphan->save();

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('panel:scheduler:tick')->assertSuccessful();

        $orphan->refresh();
        $this->assertFalse($orphan->enabled);
        $this->assertSame('failed', $orphan->last_status);
        $this->assertNull($orphan->next_run_at);
    }

    public function test_backup_run_command_reports_failure_and_keeps_the_row_intact(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (array $command): bool => ($command[0] ?? '') === 'mysqldump')
                ->andReturn(['exit' => 2, 'stdout' => '', 'stderr' => 'Access denied']);
        });

        $this->artisan('panel:backup:run', ['schedule' => $schedule->id])
            ->expectsOutputToContain('Access denied')
            ->assertFailed();

        $schedule->refresh();
        $this->assertSame('failed', $schedule->last_status);
        $this->assertStringContainsString('Access denied', (string) $schedule->last_error);
        $this->assertTrue($schedule->enabled);
    }

    public function test_backup_run_command_with_scheduled_advances_next_run_time(): void
    {
        $database = $this->makeDatabase();
        $schedule = $this->makeSchedule($database, ['next_run_at' => now()->subMinutes(5)]);

        $this->partialMock(HostCommand::class, function (MockInterface $mock): void {
            $this->mockBackupPipeline($mock);
        });

        $this->artisan('panel:backup:run', [
            'schedule' => $schedule->id,
            '--scheduled' => true,
        ])->assertSuccessful();

        $schedule->refresh();
        $this->assertSame('success', $schedule->last_status);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
    }

    public function test_backup_list_command_outputs_archives_as_json(): void
    {
        $dir = $this->tmp.'/backups/databases';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/myapp-20261009-030000.sql.gz', "GZ\n");

        $this->artisan('panel:backup:list')
            ->expectsOutputToContain('myapp-20261009-030000.sql.gz')
            ->assertSuccessful();
    }
}
