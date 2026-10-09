<?php

namespace App\Console\Commands;

use App\Support\HostCommand;
use App\Support\ServiceUnits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:service:restart
    {service : Systemd unit to restart (e.g. caddy, php8.3-fpm)}')]
#[Description('Restart an allowlisted system service (runs as root via the panel runner).')]
class PanelServiceRestart extends Command
{
    public function __construct(private readonly HostCommand $command)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = strtolower(trim((string) $this->argument('service')));

        if (! ServiceUnits::isValid($service)) {
            $this->error("Service not allowed: {$service}");

            return self::FAILURE;
        }

        $this->line("Restarting {$service}...");

        $restart = $this->command->run(['systemctl', 'restart', $service], 120);

        if ($restart['exit'] !== 0) {
            $this->error(trim($restart['stderr']) !== '' ? trim($restart['stderr']) : "systemctl exited with code {$restart['exit']}.");

            return self::FAILURE;
        }

        $state = trim($this->command->run(['systemctl', 'is-active', $service])['stdout']);

        if ($state === 'active') {
            $this->info("{$service} is active.");

            return self::SUCCESS;
        }

        $this->warn("Restart issued; current state: {$state}.");

        return self::SUCCESS;
    }
}
