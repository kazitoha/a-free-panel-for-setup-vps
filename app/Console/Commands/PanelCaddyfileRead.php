<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:caddyfile:read')]
#[Description('Print the current Caddyfile')]
class PanelCaddyfileRead extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = (string) config('panel.caddyfile');

        if (! is_file($path)) {
            $this->error("Caddyfile not found at {$path}.");

            return self::FAILURE;
        }

        $this->output->write((string) file_get_contents($path));

        return self::SUCCESS;
    }
}
