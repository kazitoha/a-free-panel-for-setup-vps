<?php

namespace App\Console\Commands;

use App\Models\BackupSchedule;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\Install;
use App\Models\Project;
use App\Models\User;
use App\Services\CaddySites;
use App\Services\PackageManager;
use App\Services\PhpVersionService;
use App\Services\SetupState;
use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Root-side: tear the panel back down to a fresh install.
 *
 * Every optional module is uninstalled, the panel's own records and files are
 * wiped, and the administrator account is removed so the next page load opens
 * /first-run again. Only the panel application, Caddy, the panel's own PHP and
 * the sudo runner survive.
 */
#[Signature('panel:reset {id? : Install record id when started from the panel} {--force : Skip the confirmation prompt}')]
#[Description('Remove every panel module and wipe the panel back to first-run')]
class PanelReset extends Command
{
    protected $hidden = true;

    /**
     * Uninstalled in this order: PowerDNS before MySQL (its database lives
     * there), phpMyAdmin before MySQL (it is served by the panel), mail first.
     *
     * @var list<string>
     */
    private const array MODULES = [
        'panel:stalwart:uninstall',
        'panel:pdns:uninstall',
        'panel:pma:uninstall',
        'panel:mysql:uninstall',
    ];

    public function __construct(
        private readonly HostCommand $command,
        private readonly PackageManager $packages,
        private readonly CaddySites $sites,
        private readonly PhpVersionService $php,
        private readonly SetupState $setup,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = $this->install();

        if ($install === null && ! $this->option('force')
            && ! $this->confirm('Remove every panel module and delete all panel data? This cannot be undone.', false)) {
            $this->warn('Reset cancelled.');

            return self::SUCCESS;
        }

        $this->mark($install, Install::STATUS_RUNNING);

        try {
            $this->uninstallModules();
            $this->removeSites();
            $this->uninstallExtraPhp();
            $this->wipePanelData($install);
            $this->wipeSystemFiles($install);
            $this->returnToFirstRun();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->mark($install, Install::STATUS_FAILED, mb_substr($exception->getMessage(), 0, 500));

            return self::FAILURE;
        }

        $this->mark($install, Install::STATUS_SUCCESS);

        $this->info('Panel reset — open the panel to create a fresh administrator.');

        return self::SUCCESS;
    }

    private function install(): ?Install
    {
        $id = $this->argument('id');

        return $id === null ? null : Install::find((int) $id);
    }

    private function mark(?Install $install, string $status, ?string $message = null): void
    {
        if ($install === null) {
            return;
        }

        $attributes = match ($status) {
            Install::STATUS_RUNNING => ['status' => $status, 'started_at' => now(), 'finished_at' => null, 'message' => null],
            Install::STATUS_FAILED => ['status' => $status, 'finished_at' => now(), 'message' => $message],
            default => ['status' => $status, 'finished_at' => now()],
        };

        $install->forceFill($attributes)->save();
    }

    private function uninstallModules(): void
    {
        foreach (self::MODULES as $command) {
            $this->line("→ {$command}");

            if (! $this->runPrivileged($command)) {
                $this->warn("{$command} reported a problem — continuing.");
            }
        }
    }

    private function removeSites(): void
    {
        foreach (Project::query()->orderBy('id')->get() as $project) {
            $this->line("Removing project {$project->name}...");
            $this->runPrivileged('panel:project:destroy', ['project' => $project->id, '--purge' => true]);
        }

        foreach ($this->sites->list() as $site) {
            if ($site['reserved']) {
                continue;
            }

            $this->line("Removing site {$site['name']}...");
            $this->runPrivileged('panel:caddy:delete', ['name' => $site['name']]);
            $this->sites->deleteSpec($site['name']);
        }
    }

    private function uninstallExtraPhp(): void
    {
        foreach ($this->php->installed() as $version) {
            if (! $this->php->removable($version)) {
                continue;
            }

            $this->line("Removing PHP {$version}...");
            $this->runPrivileged('panel:php:remove', ['--php' => $version]);
        }
    }

    private function wipePanelData(?Install $install): void
    {
        $this->line('Wiping panel records...');

        BackupSchedule::query()->delete();
        CronJob::query()->delete();
        Project::query()->delete();
        Database::query()->delete();

        if ($install === null) {
            Install::query()->delete();
        } else {
            Install::query()->whereKeyNot($install->id)->delete();
        }

        DB::table('sessions')->delete();
        DB::table('password_reset_tokens')->delete();
        User::query()->delete();

        Cache::flush();
    }

    private function wipeSystemFiles(?Install $install): void
    {
        $this->line('Clearing backups and caches...');

        $this->packages->remove(array_values(array_filter([
            config('panel.backup.dir'),
            config('panel.backup_dir'),
            config('panel.cron_file'),
            config('panel.scheduler_lock'),
        ], is_string(...))), fn (string $message) => $this->line($message));

        File::deleteDirectory(storage_path('app/site-specs'));
        File::ensureDirectoryExists(storage_path('app/site-specs'), 0755, true);
        File::delete(storage_path('app/update-status.json'));

        $keep = $install?->id;

        foreach (glob(storage_path('logs/installs').'/*.log') ?: [] as $log) {
            if ($keep === null || basename($log, '.log') !== (string) $keep) {
                @unlink($log);
            }
        }
    }

    private function returnToFirstRun(): void
    {
        $this->setup->mark([SetupState::STEP_ADMIN]);

        $this->line('Administrator account cleared — the panel will open /first-run.');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runPrivileged(string $command, array $arguments = []): bool
    {
        try {
            return $this->call($command, $arguments) === self::SUCCESS;
        } catch (Throwable $exception) {
            $this->warn($exception->getMessage());

            return false;
        }
    }
}
