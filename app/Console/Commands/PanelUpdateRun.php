<?php

namespace App\Console\Commands;

use App\Models\Install;
use App\Services\UpdateService;
use App\Support\HostCommand;
use App\Support\ServiceUnits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Root-side upgrader, spawned in the background by InstallRunner.
 *
 * Fast-forwards the checkout, reinstalls composer dependencies, rebuilds the
 * frontend assets when they changed, runs migrations, restores ownership of the
 * writable paths and restarts the panel services. Every step logs to stdout
 * (captured into the install log) and updates the installs row so the web UI
 * can poll progress.
 */
#[Signature('panel:update:run {id : Install record id}')]
#[Description('Fast-forward the panel checkout and apply the update')]
class PanelUpdateRun extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly UpdateService $updates,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = Install::find((int) $this->argument('id'));

        if ($install === null || $install->type !== 'update') {
            $this->error('Install record not found.');

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'message' => null,
        ])->save();

        try {
            $updated = $this->update();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $install->forceFill([
                'status' => Install::STATUS_FAILED,
                'message' => mb_substr($exception->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_SUCCESS,
            'finished_at' => now(),
        ])->save();

        $this->info($updated ? 'Panel updated successfully.' : 'Panel is already up to date.');

        return self::SUCCESS;
    }

    private function update(): bool
    {
        $dir = $this->updates->dir();
        $branch = $this->updates->branch();

        if (! is_dir($dir.'/.git')) {
            throw new RuntimeException('Not a git checkout — cannot update automatically.');
        }

        $this->line("Fetching origin {$branch}...");
        $this->mustRun(['git', '-C', $dir, 'fetch', '--quiet', '--prune', 'origin', $branch], 300);

        $before = $this->output(['git', '-C', $dir, 'rev-parse', 'HEAD'], 60);

        $this->line('Applying update...');
        $this->mustRun(['git', '-C', $dir, 'pull', '--quiet', '--ff-only', 'origin', $branch], 300);

        $after = $this->output(['git', '-C', $dir, 'rev-parse', 'HEAD'], 60);

        if ($before === $after) {
            $this->line('Already up to date.');
            $this->refreshStatus();

            return false;
        }

        $this->line("Updated {$before} → {$after}");

        $this->installDependencies($dir);

        if ($this->assetsChanged($dir, $before)) {
            $this->buildAssets($dir);
        }

        $this->line('Running migrations...');
        $this->mustRun(['php', 'artisan', 'migrate', '--force', '--no-interaction'], 300, $dir);
        $this->mustRun(['php', 'artisan', 'optimize:clear', '--no-interaction'], 120, $dir);

        $this->fixPermissions($dir);
        $this->syncCron($dir);
        $this->restartServices();
        $this->refreshStatus();

        return true;
    }

    private function installDependencies(string $dir): void
    {
        if (! is_file($dir.'/composer.json')) {
            return;
        }

        if (! $this->binaryAvailable('composer')) {
            $this->warn('composer is not installed on this server — skipping dependencies.');

            return;
        }

        $this->line('Installing composer dependencies...');
        $this->mustRun(
            ['composer', 'install', '--quiet', '--no-dev', '--optimize-autoloader', '--no-interaction', '--working-dir', $dir],
            600,
        );
    }

    /**
     * Frontend sources and the build tooling changed between the two commits.
     */
    private function assetsChanged(string $dir, string $before): bool
    {
        if (! is_file($dir.'/package.json')) {
            return false;
        }

        $result = $this->command->run([
            'git', '-C', $dir, 'diff', '--name-only', "{$before}..HEAD", '--',
            'resources', 'package.json', 'package-lock.json',
            'vite.config.js', 'tailwind.config.js', 'postcss.config.js',
        ], 60);

        if ($result['exit'] !== 0) {
            // Rebuild when unsure — stale assets are worse than a slow build.
            return true;
        }

        return trim($result['stdout']) !== '';
    }

    private function buildAssets(string $dir): void
    {
        if (! $this->binaryAvailable('npm')) {
            $this->warn('npm is not installed on this server — skipping asset build.');

            return;
        }

        $this->line('Building frontend assets...');
        $this->mustRun(['npm', 'install', '--silent', '--ignore-scripts'], 600, $dir);
        $this->mustRun(['npm', 'run', 'build'], 600, $dir);
    }

    private function fixPermissions(string $dir): void
    {
        foreach (['storage', 'bootstrap/cache'] as $writable) {
            if (is_dir($dir.'/'.$writable)) {
                $this->mustRun(['chown', '-R', 'www-data:www-data', $dir.'/'.$writable], 120);
            }
        }

        if (is_file($dir.'/.env')) {
            $this->mustRun(['chgrp', 'www-data', $dir.'/.env'], 30);
            $this->mustRun(['chmod', '640', $dir.'/.env'], 30);
        }
    }

    /**
     * Propagate the new hourly update-check line (and any other fixed line)
     * to /etc/cron.d/panel.
     */
    private function syncCron(string $dir): void
    {
        $result = $this->command->run(['php', 'artisan', 'panel:cron:sync', '--no-interaction'], 60, null, $dir);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            $this->warn('Could not refresh the panel cron file'
                .($detail !== '' ? ': '.mb_substr($detail, 0, 300) : '.'));
        }
    }

    private function restartServices(): void
    {
        $units = ['php'.config('panel.php').'-fpm', 'caddy'];

        foreach ($units as $unit) {
            if (! ServiceUnits::isValid($unit)) {
                $this->warn("Service not allowed: {$unit}");

                continue;
            }

            $result = $this->command->run(['systemctl', 'restart', $unit], 120);

            if ($result['exit'] !== 0) {
                $detail = trim($result['stderr'].$result['stdout']);

                $this->warn("Could not restart {$unit}"
                    .($detail !== '' ? ': '.mb_substr($detail, 0, 300) : " (exit {$result['exit']})."));

                continue;
            }

            $this->info("Restarted {$unit}.");
        }
    }

    private function refreshStatus(): void
    {
        $this->updates->check();
        $this->updates->keepWebWritable();
    }

    private function output(array $command, float $timeout): string
    {
        return trim($this->mustRun($command, $timeout)['stdout']);
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function mustRun(array $command, float $timeout = 300, ?string $cwd = null): array
    {
        $result = $this->command->run($command, $timeout, null, $cwd);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Command failed: '.implode(' ', $command)
                .($detail !== '' ? ' — '.mb_substr($detail, 0, 500) : '')
            );
        }

        return $result;
    }

    private function binaryAvailable(string $binary): bool
    {
        return $this->command->run([$binary, '--version'], 15)['exit'] === 0;
    }
}
