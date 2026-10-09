<?php

namespace App\Console\Commands;

use App\Services\UpdateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Root-side update checker, spawned by the hourly cron line and by the
 * dashboard's Check button (through the sudo runner).
 *
 * Fetches the upstream branch, records how far HEAD is behind it and keeps the
 * status file writable by the web user. Failures are recorded in the status so
 * the caller can report them instead of the page silently showing nothing.
 */
#[Signature('panel:update:check')]
#[Description('Record whether the panel checkout is behind its upstream branch')]
class PanelUpdateCheck extends Command
{
    protected $hidden = true;

    public function __construct(private readonly UpdateService $updates)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $status = $this->updates->check();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->updates->keepWebWritable();

        if ($status['error'] !== null) {
            $this->warn('Update check failed: '.$status['error']);

            return self::FAILURE;
        }

        $this->info(
            $status['update_available']
                ? "Update available: {$status['behind']} commit(s) behind {$status['branch']}."
                : 'Panel is up to date.'
        );

        return self::SUCCESS;
    }
}
