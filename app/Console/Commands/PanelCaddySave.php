<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidSiteSpec;
use App\Services\CaddyFileWriter;
use App\Support\SiteSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Root-side: validate a saved site specification and apply it to Caddy with
 * backup → validate → reload safety. Invoked through the sudo wrapper.
 */
#[Signature('panel:caddy:save {name : Site file name (e.g. example.com)} {--spec= : Absolute path to the JSON specification}')]
#[Description('Validate and apply a Caddy site file with automatic rollback')]
class PanelCaddySave extends Command
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
        $specPath = (string) $this->option('spec');

        if (! SiteSpec::isValidName($name)) {
            $this->error("Invalid site name: {$name}");

            return self::FAILURE;
        }

        if (in_array($name, config('panel.reserved_sites', []), true)) {
            $this->error("{$name} is managed by the panel and cannot be edited here.");

            return self::FAILURE;
        }

        $real = realpath($specPath) ?: false;
        $specsDir = realpath(storage_path('app/site-specs')) ?: false;

        if ($real === false || $specsDir === false || ! str_starts_with($real, $specsDir.DIRECTORY_SEPARATOR)) {
            $this->error('Specification file must live in storage/app/site-specs.');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($real), true);

        if (! is_array($decoded)) {
            $this->error('Specification file is not valid JSON.');

            return self::FAILURE;
        }

        try {
            $spec = SiteSpec::validate($decoded);
        } catch (InvalidSiteSpec $exception) {
            foreach ($exception->errors as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $this->writer->write($name, SiteSpec::build($spec));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Site {$name} saved and Caddy reloaded.");

        return self::SUCCESS;
    }
}
