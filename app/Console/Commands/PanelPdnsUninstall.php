<?php

namespace App\Console\Commands;

use App\Services\PackageManager;
use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Root-side: remove PowerDNS, its packages and configuration.
 *
 * The `pdns` MySQL database is intentionally left alone — it disappears with
 * MySQL when `panel:reset` removes that module, and keeping this command off
 * the database makes a standalone uninstall safe to run.
 */
#[Signature('panel:pdns:uninstall')]
#[Description('Remove PowerDNS and its configuration')]
class PanelPdnsUninstall extends Command
{
    protected $hidden = true;

    /**
     * @var list<string>
     */
    private const array PACKAGES = [
        'pdns-server',
        'pdns-backend-mysql',
        'pdns-backend-pipe',
    ];

    public function __construct(
        private readonly HostCommand $command,
        private readonly PackageManager $packages,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $installed = $this->packages->installed(self::PACKAGES);

        if ($installed === []) {
            $this->line('PowerDNS is not installed — cleaning up any leftover configuration.');

            $this->packages->remove(
                ['/etc/powerdns', '/var/lib/powerdns', '/var/log/powerdns'],
                fn (string $message) => $this->line($message),
            );

            return self::SUCCESS;
        }

        $this->line('Stopping PowerDNS...');
        $this->command->run(['systemctl', 'disable', '--now', 'pdns'], 120.0);

        if (! $this->packages->purge($installed, fn (string $message) => $this->line($message))) {
            $this->error('Could not remove the PowerDNS packages.');

            return self::FAILURE;
        }

        $this->packages->remove(
            ['/etc/powerdns', '/var/lib/powerdns', '/var/log/powerdns'],
            fn (string $message) => $this->line($message),
        );

        $this->info('PowerDNS removed.');

        return self::SUCCESS;
    }
}
