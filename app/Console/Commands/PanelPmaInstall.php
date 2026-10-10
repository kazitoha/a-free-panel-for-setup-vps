<?php

namespace App\Console\Commands;

use App\Models\Install;
use App\Services\CaddyFileWriter;
use App\Support\HostCommand;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Root-side phpMyAdmin installer, spawned in the background by InstallRunner.
 *
 * Downloads the pinned phpMyAdmin release, swaps it into place, writes the
 * pma.<panel-domain> Caddy site and reloads Caddy. Every step logs to stdout
 * (captured into the install log) and updates the installs row so the web UI
 * can poll progress.
 */
#[Signature('panel:pma:install {id : Install record id} {--release= : phpMyAdmin release (default from config)}')]
#[Description('Download, install and serve phpMyAdmin behind Caddy')]
class PanelPmaInstall extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly CaddyFileWriter $writer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = Install::find((int) $this->argument('id'));

        if ($install === null || $install->type !== 'pma') {
            $this->error('Install record not found.');

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'message' => null,
        ])->save();

        $version = (string) ($this->option('release') ?: config('panel.phpmyadmin.version'));

        try {
            $this->runSteps($install, $version);
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

        $this->info('phpMyAdmin installed successfully.');

        return self::SUCCESS;
    }

    private function runSteps(Install $install, string $version): void
    {
        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
            throw new RuntimeException("Invalid phpMyAdmin version: {$version}");
        }

        $dir = (string) config('panel.phpmyadmin.dir');
        $tarball = '/tmp/phpmyadmin-'.$version.'.tar.gz';
        $url = "https://files.phpmyadmin.net/phpMyAdmin/{$version}/phpMyAdmin-{$version}-all-languages.tar.gz";

        // 1. Download -----------------------------------------------------
        $this->line("Downloading phpMyAdmin {$version}...");
        $this->fetch($url, $tarball);

        // 2. Extract ------------------------------------------------------
        $this->line('Extracting archive...');
        $stage = '/tmp/phpmyadmin-stage-'.getmypid();

        try {
            $this->mustRun(['rm', '-rf', $stage]);
            $this->mustRun(['mkdir', '-p', $stage]);
            $this->mustRun(['tar', '-xzf', $tarball, '-C', $stage]);

            $extracted = $stage.'/phpMyAdmin-'.$version.'-all-languages';

            if (! is_file($extracted.'/index.php')) {
                throw new RuntimeException('Archive did not contain the expected phpMyAdmin layout.');
            }

            // 3. Swap into place ------------------------------------------
            $this->line("Installing to {$dir}...");
            $old = $dir.'.old';

            if (is_dir($dir)) {
                $this->mustRun(['rm', '-rf', $old]);
                $this->mustRun(['mv', $dir, $old]);
            }

            try {
                $this->mustRun(['mv', $extracted, $dir]);
            } catch (RuntimeException $swapFailure) {
                // Roll the previous installation back.
                if (is_dir($old)) {
                    @mkdir(dirname($dir), 0755, true);
                    $this->mustRun(['mv', $old, $dir]);
                }

                throw $swapFailure;
            }

            $this->mustRun(['rm', '-rf', $old]);
            $this->mustRun(['chown', '-R', 'www-data:www-data', $dir]);
        } finally {
            @unlink($tarball);
            $this->command->run(['rm', '-rf', $stage]);
        }

        // 4. Caddy site ---------------------------------------------------
        $this->line('Configuring Caddy site...');
        $spec = SiteSpec::validate([
            'domain' => 'pma.'.config('panel.domain'),
            'wildcard' => false,
            'type' => 'php',
            'php_version' => config('panel.php'),
            'docroot' => $dir,
        ]);

        $this->writer->write('pma', SiteSpec::build($spec));

        $this->info('Caddy site pma.'.config('panel.domain').' is live.');
    }

    /**
     * Download a file with curl, failing loudly on any error.
     */
    private function fetch(string $url, string $destination): void
    {
        $result = $this->command->run([
            'curl', '-fsSL', '--connect-timeout', '15', '--max-time', '300',
            '-o', $destination, $url,
        ], 320.0);

        if ($result['exit'] !== 0 || ! is_file($destination) || filesize($destination) < 100_000) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Download failed.'
                .($detail !== '' ? ' '.mb_substr($detail, 0, 400) : '')
            );
        }
    }

    private function mustRun(array $command): void
    {
        $result = $this->command->run($command, 300);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            throw new RuntimeException(
                'Command failed: '.implode(' ', $command)
                .($detail !== '' ? ' — '.mb_substr($detail, 0, 400) : '')
            );
        }
    }
}
