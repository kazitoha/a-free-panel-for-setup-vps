<?php

namespace Tests\Feature;

use App\Models\BackupSchedule;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\Install;
use App\Models\Project;
use App\Models\User;
use App\Support\HostCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelResetTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $sitesDir;

    private string $phpDir;

    private string $wwwDir;

    private string $caddyfile;

    private string $marker;

    private string $cronFile;

    /**
     * @var list<list<string>>
     */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/panel-reset-'.uniqid();
        $this->sitesDir = $this->dir.'/sites';
        $this->phpDir = $this->dir.'/php';
        $this->wwwDir = $this->dir.'/www';
        $this->caddyfile = $this->dir.'/Caddyfile';

        foreach ([$this->sitesDir, $this->phpDir, $this->wwwDir] as $directory) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->caddyfile, "{}\n");

        $this->marker = $this->dir.'/setup-pending.json';
        $this->cronFile = $this->dir.'/cron.d-panel';
        file_put_contents($this->cronFile, '');
        file_put_contents($this->dir.'/panel.lock', '');
        mkdir($this->dir.'/panel-backups', 0755, true);
        mkdir($this->dir.'/caddy-backups', 0755, true);

        config([
            'panel.php' => '8.3',
            'panel.php_dir' => $this->phpDir,
            'panel.caddy_sites_dir' => $this->sitesDir,
            'panel.caddyfile' => $this->caddyfile,
            'panel.backup_dir' => $this->dir.'/caddy-backups',
            'panel.www_dir' => $this->wwwDir,
            'panel.backup.dir' => $this->dir.'/panel-backups',
            'panel.cron_file' => $this->cronFile,
            'panel.scheduler_lock' => $this->dir.'/panel.lock',
            'panel.setup.marker' => $this->marker,
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_cli_asks_for_confirmation_unless_forced(): void
    {
        $this->fakeHost();

        $this->artisan('panel:reset')
            ->expectsConfirmation('Remove every panel module and delete all panel data? This cannot be undone.', 'no')
            ->expectsOutputToContain('Reset cancelled.')
            ->assertSuccessful();

        $this->assertSame(0, Install::count());
    }

    public function test_it_uninstalls_every_module_and_returns_to_first_run(): void
    {
        User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'session-1',
            'user_id' => 1,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        Database::create(['name' => 'appdb', 'username' => 'appuser', 'password' => 'secret']);
        Project::create([
            'name' => 'proj',
            'repo_url' => 'git@example.com:proj.git',
            'branch' => 'main',
            'domain' => 'proj.example.com',
            'php_version' => '8.3',
            'path' => $this->wwwDir.'/proj',
        ]);
        BackupSchedule::create([
            'target_type' => 'project',
            'target_id' => 1,
            'times_per_day' => 1,
            'enabled' => true,
        ]);
        CronJob::create([
            'name' => 'tick',
            'cron_minute' => '*',
            'cron_hour' => '*',
            'cron_dom' => '*',
            'cron_month' => '*',
            'cron_dow' => '*',
            'command' => 'php artisan schedule:run',
        ]);

        $moduleInstall = Install::create([
            'type' => 'mysql',
            'label' => 'Install MySQL',
            'command' => 'panel:mysql:install',
            'status' => Install::STATUS_SUCCESS,
        ]);

        $reset = Install::create([
            'type' => 'reset',
            'label' => 'Reset the panel',
            'command' => 'panel:reset',
            'status' => Install::STATUS_PENDING,
        ]);

        file_put_contents($this->sitesDir.'/example.com.caddy', "example.com {\n\treverse_proxy 127.0.0.1:9000\n}\n");
        file_put_contents($this->sitesDir.'/proj.example.com.caddy', "proj.example.com {\n\treverse_proxy 127.0.0.1:9000\n}\n");
        mkdir($this->wwwDir.'/proj', 0755, true);
        mkdir($this->phpDir.'/8.2', 0755, true);
        mkdir($this->phpDir.'/8.3', 0755, true);

        $this->fakeHost();

        $this->artisan('panel:reset', ['id' => $reset->id])
            ->expectsOutputToContain('Panel reset')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Database::count());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, BackupSchedule::count());
        $this->assertSame(0, CronJob::count());
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame(1, Install::count());
        $this->assertSame($reset->id, Install::first()->id);
        $this->assertSame(Install::STATUS_SUCCESS, $reset->fresh()->status);

        $this->assertFileExists($this->marker);
        $this->assertStringContainsString('admin', (string) file_get_contents($this->marker));

        $this->assertFileDoesNotExist($this->sitesDir.'/example.com.caddy');
        $this->assertFileDoesNotExist($this->sitesDir.'/proj.example.com.caddy');

        $this->assertTrue($this->ran('dpkg', '-s', 'mysql-server'));
        $this->assertTrue($this->ran('systemctl', 'disable', '--now', 'mysql'));
        $this->assertTrue($this->purged('mysql-server'));

        $phpRemove = $this->find('apt-get', 'remove');
        $this->assertNotNull($phpRemove);
        $this->assertContains('php8.2-fpm', $phpRemove);
        $this->assertNotContains('php8.3-fpm', $phpRemove);

        $this->assertTrue($this->ranRm($this->cronFile));

        $this->assertModelMissing($moduleInstall);
    }

    public function test_force_skips_the_confirmation(): void
    {
        User::factory()->create();
        $this->fakeHost();

        $this->artisan('panel:reset', ['--force' => true])
            ->expectsOutputToContain('Panel reset')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertFileExists($this->marker);
    }

    private function fakeHost(): void
    {
        $installed = ['mysql-server', 'mysql-client', 'mysql-common', 'pdns-server', 'pdns-backend-mysql'];

        $this->partialMock(HostCommand::class, function (MockInterface $mock) use ($installed): void {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($installed): array {
                $this->commands[] = array_values(array_map('strval', $command));

                if (($command[0] ?? '') === 'dpkg' && ($command[1] ?? '') === '-s') {
                    return in_array($command[2] ?? '', $installed, true)
                        ? ['exit' => 0, 'stdout' => '', 'stderr' => '']
                        : ['exit' => 1, 'stdout' => '', 'stderr' => ''];
                }

                if (($command[0] ?? '') === 'systemctl' && ($command[1] ?? '') === 'cat') {
                    return ['exit' => 1, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            });
        });
    }

    private function ran(string ...$prefix): bool
    {
        $prefix = array_values($prefix);

        foreach ($this->commands as $command) {
            if (array_slice($command, 0, count($prefix)) === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     */
    private function find(string $first, string $second): ?array
    {
        foreach ($this->commands as $command) {
            if (($command[0] ?? '') === $first && ($command[1] ?? '') === $second) {
                return $command;
            }
        }

        return null;
    }

    private function ranRm(string $path): bool
    {
        foreach ($this->commands as $command) {
            if (($command[0] ?? '') === 'rm' && in_array($path, $command, true)) {
                return true;
            }
        }

        return false;
    }

    private function purged(string $package): bool
    {
        foreach ($this->commands as $command) {
            if (($command[0] ?? '') === 'apt-get' && ($command[1] ?? '') === 'purge' && in_array($package, $command, true)) {
                return true;
            }
        }

        return false;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;
            is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
