<?php

namespace App\Console\Commands;

use App\Services\CaddyFileWriter;
use App\Support\HostCommand;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Root-side: remove phpMyAdmin and its Caddy site.
 */
#[Signature('panel:pma:uninstall')]
#[Description('Remove phpMyAdmin files and its Caddy site')]
class PanelPmaUninstall extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly CaddyFileWriter $writer,
        private readonly HostCommand $command,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dir = (string) config('panel.phpmyadmin.dir');

        if (is_dir($dir)) {
            $this->line("Removing {$dir}...");

            $result = $this->command->run(['rm', '-rf', $dir, $dir.'.old'], 60);

            if ($result['exit'] !== 0) {
                $this->error('Could not remove phpMyAdmin: '.trim($result['stderr']));

                return self::FAILURE;
            }
        } else {
            $this->line('phpMyAdmin directory not present — skipping.');
        }

        try {
            if (SiteSpec::isValidName('pma')
                && is_file(config('panel.caddy_sites_dir').'/pma.caddy')) {
                $this->line('Removing Caddy site pma...');
                $this->writer->remove('pma');
            } else {
                $this->line('Caddy site pma not present — skipping.');
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('phpMyAdmin uninstalled.');

        return self::SUCCESS;
    }
}
