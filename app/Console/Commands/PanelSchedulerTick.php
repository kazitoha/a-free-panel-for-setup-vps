<?php

namespace App\Console\Commands;

use App\Models\BackupSchedule;
use App\Services\BackupScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:scheduler:tick')]
#[Description('Run every due backup schedule and advance the next run times')]
class PanelSchedulerTick extends Command
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
        $lock = $this->acquireLock();

        if ($lock === null) {
            // Another tick still holds the lock — nothing to do.
            return self::SUCCESS;
        }

        try {
            $this->runDue();
            $this->repairMissingNextRunTimes();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }

    private function runDue(): void
    {
        $due = BackupSchedule::where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($due as $schedule) {
            $result = $this->scheduler->runSchedule($schedule);

            $this->line(sprintf(
                '  #%d %s: %s',
                $schedule->id,
                $schedule->targetLabel(),
                $result['message'],
            ));

            $schedule->refresh();

            if ($schedule->enabled) {
                $schedule->update(['next_run_at' => $schedule->computeNextRunAt()]);
            }
        }

        if ($due->isEmpty()) {
            $this->line('  No schedules due.');
        }
    }

    /**
     * Enabled schedules that lost their next run time (fresh rows, repairs).
     */
    private function repairMissingNextRunTimes(): void
    {
        BackupSchedule::where('enabled', true)
            ->whereNull('next_run_at')
            ->orderBy('id')
            ->get()
            ->each(fn (BackupSchedule $schedule) => $schedule->update([
                'next_run_at' => $schedule->computeNextRunAt(),
            ]));
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        $path = (string) config('panel.scheduler_lock');
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            $this->warn("Could not open scheduler lock at {$path}.");

            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }
}
