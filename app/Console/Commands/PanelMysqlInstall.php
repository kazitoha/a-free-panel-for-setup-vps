<?php

namespace App\Console\Commands;

use App\Models\Install;
use App\Services\MySqlSetup;
use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Root-side MySQL installer, spawned in the background by InstallRunner when
 * the Databases module has no working database yet.
 *
 * Installs mysql-server when it is missing, finds or writes a working root
 * password and stores it in .env and /root/.panel-secrets. Every step logs to
 * stdout (captured into the install log) and updates the installs row so the
 * web UI can poll progress.
 */
#[Signature('panel:mysql:install {id : Install record id}')]
#[Description('Install MySQL and store a working root password')]
class PanelMysqlInstall extends Command
{
    protected $hidden = true;

    public function __construct(
        private readonly HostCommand $command,
        private readonly MySqlSetup $mysql,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = Install::find((int) $this->argument('id'));

        if ($install === null || $install->type !== 'mysql') {
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
            $this->mysql->ensure(fn (string $message) => $this->line($message));
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

        $this->info('MySQL is ready. The root password was stored in .env and '.config('panel.secrets_file').'.');

        return self::SUCCESS;
    }
}
