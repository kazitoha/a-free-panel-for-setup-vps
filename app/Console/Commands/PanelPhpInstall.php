<?php

namespace App\Console\Commands;

use App\Models\Install;
use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Root-side PHP installer, spawned in the background by InstallRunner.
 *
 * Installs one PHP version with the extensions the panel's sites need and
 * enables its FPM service, so the version shows up in the Sites and Projects
 * pickers straight away.
 */
#[Signature('panel:php:install {id : Install record id} {--php= : PHP version to install (for example 8.4)}')]
#[Description('Install a PHP version with FPM, CLI and the common extensions')]
class PanelPhpInstall extends Command
{
    protected $hidden = true;

    public function __construct(private readonly HostCommand $command)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = Install::find((int) $this->argument('id'));

        if ($install === null || $install->type !== 'php') {
            $this->error('Install record not found.');

            return self::FAILURE;
        }

        $version = (string) $this->option('php');

        if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
            $this->markFailed($install, "Invalid PHP version: {$version}");

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'message' => null,
        ])->save();

        try {
            $this->runSteps($install, $version);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->markFailed($install, $exception->getMessage());

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_SUCCESS,
            'finished_at' => now(),
        ])->save();

        $this->info("PHP {$version} is ready.");

        return self::SUCCESS;
    }

    private function runSteps(Install $install, string $version): void
    {
        $packages = $this->packages($version);

        if (is_dir((string) config('panel.php_dir').'/'.$version)) {
            $this->line("PHP {$version} is already present — refreshing its packages...");
        }

        if (! $this->hasPackage('php'.$version.'-fpm')) {
            $this->line('Adding the Ondrej PHP repository...');
            $this->mustRun(['add-apt-repository', '-y', 'ppa:ondrej/php']);
        }

        $this->line('Refreshing the package lists...');
        $this->mustRun(['apt-get', 'update', '-qq']);

        if (! $this->hasPackage('php'.$version.'-fpm')) {
            throw new RuntimeException(
                "PHP {$version} is not available from the configured repositories — "
                .'it may not be published for this Ubuntu release.'
            );
        }

        $this->line("Installing PHP {$version}...");
        $this->mustRun(['apt-get', 'install', '-y', '--no-install-recommends', ...$packages]);

        $this->line("Enabling php{$version}-fpm...");
        $this->mustRun(['systemctl', 'enable', '--now', "php{$version}-fpm"]);

        $this->line("PHP {$version} installed to ".config('panel.php_dir')."/{$version}.");
    }

    /**
     * The same extension set install-panel.sh installs for the panel's PHP.
     *
     * @return list<string>
     */
    private function packages(string $version): array
    {
        return array_map(
            fn (string $suffix): string => "php{$version}-{$suffix}",
            ['fpm', 'cli', 'mysql', 'curl', 'gd', 'mbstring', 'xml', 'zip', 'intl', 'sqlite3'],
        );
    }

    /**
     * Whether apt knows a package with an installable candidate.
     */
    private function hasPackage(string $package): bool
    {
        return $this->command->run(['apt-cache', 'show', $package], 30.0)['exit'] === 0;
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command): void
    {
        $result = $this->command->run($command, 900.0, ['DEBIAN_FRONTEND' => 'noninteractive']);

        if ($result['exit'] === 0) {
            return;
        }

        $message = 'Command failed: '.implode(' ', $command);
        $detail = $this->failureDetail($result['stdout'], $result['stderr']);

        if ($detail !== '') {
            $message .= ' — '.$detail;
        }

        throw new RuntimeException($message);
    }

    /**
     * The tail of apt's error channel is where the real reason lives. Its
     * start is often a wall of "No apport report written" notices from
     * crashed maintainer scripts, which pushes the actual error out of view.
     */
    private function failureDetail(string $stdout, string $stderr): string
    {
        $lines = $this->meaningfulLines($stderr);

        if ($lines === []) {
            $lines = $this->meaningfulLines($stdout);
        }

        return mb_substr(implode(' / ', array_slice($lines, -6)), 0, 400);
    }

    /**
     * @return list<string>
     */
    private function meaningfulLines(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];

        return array_values(array_filter(
            $lines,
            fn (string $line): bool => trim($line) !== ''
                && ! str_contains($line, 'apport report written'),
        ));
    }

    private function markFailed(Install $install, string $message): void
    {
        $install->forceFill([
            'status' => Install::STATUS_FAILED,
            'message' => mb_substr($message, 0, 500),
            'finished_at' => now(),
        ])->save();
    }
}
