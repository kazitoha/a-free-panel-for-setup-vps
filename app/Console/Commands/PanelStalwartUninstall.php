<?php

namespace App\Console\Commands;

use App\Services\CaddyFileWriter;
use App\Services\PackageManager;
use App\Support\HostCommand;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Root-side: remove the Stalwart mail server the Email module installed.
 *
 * Stalwart ships through its own install.sh rather than apt, so there are no
 * packages to purge — the systemd unit, its directories and the mail Caddy
 * site are removed instead.
 */
#[Signature('panel:stalwart:uninstall')]
#[Description('Remove Stalwart mail, its files and its Caddy site')]
class PanelStalwartUninstall extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly PackageManager $packages,
        private readonly CaddyFileWriter $writer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $unit = $this->detectUnit();

        if ($unit === null && ! is_dir('/etc/stalwart') && ! $this->hasMailSite()) {
            $this->line('Stalwart is not installed — nothing to remove.');

            return self::SUCCESS;
        }

        if ($unit !== null) {
            $this->line("Stopping {$unit}...");
            $this->command->run(['systemctl', 'disable', '--now', $unit], 120.0);
        }

        $this->packages->remove([
            '/opt/stalwart',
            '/opt/stalwart-mail',
            '/etc/stalwart',
            '/var/lib/stalwart',
            '/var/lib/stalwart-mail',
            '/var/log/stalwart',
            '/usr/local/bin/stalwart',
            '/etc/systemd/system/stalwart.service',
            '/etc/systemd/system/stalwart-mail.service',
        ], fn (string $message) => $this->line($message));

        $this->command->run(['systemctl', 'daemon-reload'], 30.0);

        try {
            if ($this->hasMailSite()) {
                $this->line('Removing Caddy site mail...');
                $this->writer->remove('mail');
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Stalwart mail removed.');

        return self::SUCCESS;
    }

    /**
     * 'stalwart' | 'stalwart-mail' | null when neither unit exists.
     */
    private function detectUnit(): ?string
    {
        foreach (['stalwart', 'stalwart-mail'] as $candidate) {
            if ($this->command->run(['systemctl', 'cat', $candidate], 15.0)['exit'] === 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function hasMailSite(): bool
    {
        return SiteSpec::isValidName('mail')
            && is_file(config('panel.caddy_sites_dir').'/mail.caddy');
    }
}
