<?php

namespace App\Console\Commands;

use App\Services\PackageManager;
use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Root-side: remove the MySQL server and everything it stores.
 *
 * Used by the Databases module and by `panel:reset`. The panel's own SQLite
 * storage is untouched — only the MySQL server the panel installed (or
 * adopted) is removed, together with /var/lib/mysql and its configuration.
 */
#[Signature('panel:mysql:uninstall')]
#[Description('Remove the MySQL server and all its databases')]
class PanelMysqlUninstall extends Command
{
    protected $hidden = true;

    /**
     * @var list<string>
     */
    private const array PACKAGES = [
        'mysql-server',
        'mysql-server-8.0',
        'mysql-server-core-8.0',
        'mysql-client',
        'mysql-client-8.0',
        'mysql-client-core-8.0',
        'mysql-common',
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
            $this->line('MySQL is not installed — cleaning up any leftover data.');

            $this->packages->remove(
                ['/var/lib/mysql', '/etc/mysql', '/var/log/mysql'],
                fn (string $message) => $this->line($message),
            );

            return self::SUCCESS;
        }

        $this->line('Stopping MySQL...');
        $this->command->run(['systemctl', 'disable', '--now', 'mysql'], 120.0);
        $this->command->run(['systemctl', 'disable', '--now', 'mysqld'], 120.0);

        if (! $this->packages->purge($installed, fn (string $message) => $this->line($message))) {
            $this->error('Could not remove the MySQL packages.');

            return self::FAILURE;
        }

        $this->packages->remove(
            ['/var/lib/mysql', '/etc/mysql', '/var/log/mysql'],
            fn (string $message) => $this->line($message),
        );

        $this->info('MySQL removed.');

        return self::SUCCESS;
    }
}
