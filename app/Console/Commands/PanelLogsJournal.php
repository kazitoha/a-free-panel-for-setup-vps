<?php

namespace App\Console\Commands;

use App\Support\HostCommand;
use App\Support\ServiceUnits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:logs:journal
    {unit : Systemd unit (allowlisted)}
    {--lines=200 : How many lines to show (50-1000)}')]
#[Description('Print recent journal entries for an allowlisted systemd unit')]
class PanelLogsJournal extends Command
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
        $unit = strtolower(trim((string) $this->argument('unit')));

        if (! ServiceUnits::isValid($unit)) {
            $this->error("Service not allowed: {$unit}");

            return self::FAILURE;
        }

        $lines = (int) $this->option('lines');

        if ($lines < 50 || $lines > 1000) {
            $this->error('--lines must be between 50 and 1000.');

            return self::FAILURE;
        }

        $result = $this->command->run([
            'journalctl', '-u', $unit, '-n', (string) $lines, '--no-pager', '-o', 'short-iso',
        ], 30.0);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : "journalctl exited with code {$result['exit']}.";

            $this->error($detail);

            return self::FAILURE;
        }

        $output = trim($result['stdout']);

        if ($output === '') {
            $this->warn("No journal entries for {$unit}.");

            return self::SUCCESS;
        }

        $this->output->write($output."\n");

        return self::SUCCESS;
    }
}
