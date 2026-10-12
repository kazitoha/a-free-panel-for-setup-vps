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

            foreach ($this->failureContext($service) as $line) {
                $this->line($line);
            }

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

    /**
     * systemd only says "the control process exited with error code" — the
     * reason itself is in the unit's journal, and for MySQL/MariaDB in the
     * server's own error log, which systemd never sees. Show those lines so
     * the panel reports the actual failure (a config typo, a port clash, an
     * out-of-memory kill) instead of a dead end.
     *
     * @return list<string>
     */
    private function failureContext(string $service): array
    {
        $lines = [];

        $journal = $this->recentLines(
            ['journalctl', '-u', $service, '-n', '12', '--no-pager', '-o', 'cat'],
            6,
        );

        if ($journal !== []) {
            $lines[] = "Recent messages for {$service}:";
            $lines = [...$lines, ...$journal];
        }

        if (str_starts_with($service, 'mysql') || str_starts_with($service, 'mariadb')) {
            $errorLog = $this->recentLines(['tail', '-n', '12', '/var/log/mysql/error.log'], 6);

            if ($errorLog !== []) {
                $lines[] = 'MySQL error log (/var/log/mysql/error.log):';
                $lines = [...$lines, ...$errorLog];
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function recentLines(array $command, int $keep): array
    {
        $result = $this->command->run($command, 20.0);

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", trim($result['stdout']))),
            static fn (string $line): bool => $line !== '',
        ));

        return array_slice($lines, -$keep);
    }
}
