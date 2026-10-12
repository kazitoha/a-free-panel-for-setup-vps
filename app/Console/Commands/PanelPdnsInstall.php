<?php

namespace App\Console\Commands;

use App\Models\Install;
use App\Services\PowerDnsSetup;
use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Root-side PowerDNS installer, spawned in the background by InstallRunner
 * when the DNS module has no server answering on 127.0.0.1:8081.
 *
 * Installs the package (MySQL first, through MySqlSetup), creates the pdns
 * database and schema, writes pdns.conf with generated credentials, starts the
 * service and seeds the initial zones. Every step logs to stdout (captured
 * into the install log) and updates the installs row for the web UI.
 */
#[Signature('panel:pdns:install {id : Install record id}')]
#[Description('Install PowerDNS, its database, API and initial zones')]
class PanelPdnsInstall extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly PowerDnsSetup $pdns,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = Install::find((int) $this->argument('id'));

        if ($install === null || $install->type !== 'pdns') {
            $this->error('Install record not found.');

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'message' => null,
        ])->save();

        try {
            $this->pdns->ensure(fn (string $message) => $this->line($message));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $install->forceFill([
                'status' => Install::STATUS_FAILED,
                'message' => mb_substr($exception->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            return self::FAILURE;
        }

        $install->forceFill([
            'status' => Install::STATUS_SUCCESS,
            'finished_at' => now(),
        ])->save();

        $this->info('PowerDNS is ready. The API key was stored in .env and '.config('panel.secrets_file').'.');

        return self::SUCCESS;
    }
}
