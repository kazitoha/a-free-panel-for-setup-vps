<?php

namespace App\Console\Commands;

use App\Models\BackupSchedule;
use App\Services\BackupScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:backup:run
    {schedule : Backup schedule id}
    {--scheduled : Record the run as a scheduled tick and advance next_run_at}')]
#[Description('Run one backup schedule now')]
class PanelBackupRun extends Command
{
    protected $hidden = true;

    public function __construct(private readonly BackupScheduler $scheduler)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $schedule = BackupSchedule::find((int) $this->argument('schedule'));

        if ($schedule === null) {
            $this->error('Backup schedule not found.');

            return self::FAILURE;
        }

        $result = $this->scheduler->runSchedule($schedule);

        if ($result['status'] === 'success') {
            $this->info($result['message']);
        } else {
            $this->error($result['message']);
        }

        if ($this->option('scheduled')) {
            $schedule->refresh();

            if ($schedule->enabled) {
                $schedule->update(['next_run_at' => $schedule->computeNextRunAt()]);
            }
        }

        return $result['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
