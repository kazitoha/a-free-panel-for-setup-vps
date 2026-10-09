<?php

namespace App\Console\Commands;

use App\Services\CaddyFileWriter;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Root-side: remove a site file from Caddy with backup → validate → reload.
 */
#[Signature('panel:caddy:delete {name : Site file name (e.g. example.com)}')]
#[Description('Remove a Caddy site file with automatic rollback')]
class PanelCaddyDelete extends Command
{
    public function __construct(private readonly CaddyFileWriter $writer)
    {
        parent::__construct();
    }

    protected $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! SiteSpec::isValidName($name)) {
            $this->error("Invalid site name: {$name}");

            return self::FAILURE;
        }

        if (in_array($name, config('panel.reserved_sites', []), true)) {
            $this->error("{$name} is managed by the panel and cannot be deleted.");

            return self::FAILURE;
        }

        try {
            $this->writer->remove($name);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Site {$name} removed and Caddy reloaded.");

        return self::SUCCESS;
    }
}
