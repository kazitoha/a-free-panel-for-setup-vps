<?php

namespace App\Services;

use App\Models\BackupSchedule;
use App\Models\CronJob;

/**
 * Post-run health checks for the dashboard: are backup schedules firing,
 * did cron jobs succeed, is /etc/cron.d/panel installed, and is there
 * enough disk space to keep writing archives.
 */
class HealthService
{
    /**
     * A schedule is overdue (scheduler/cron broken) after this grace period.
     */
    private const int OVERDUE_MINUTES = 15;

    /**
     * @return list<array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    public function report(): array
    {
        return [
            $this->cronFileCheck(),
            $this->backupSchedulesCheck(),
            $this->cronJobsCheck(),
            $this->diskCheck(),
        ];
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    private function cronFileCheck(): array
    {
        $file = (string) config('panel.cron_file');
        $scheduled = BackupSchedule::where('enabled', true)->exists();
        $jobs = CronJob::where('enabled', true)->exists();

        if (! $scheduled && ! $jobs) {
            return $this->check('cron_file', 'Cron file', 'ok', 'No scheduled jobs yet.');
        }

        if (! is_file($file)) {
            return $this->check(
                'cron_file',
                'Cron file',
                'fail',
                "{$file} is missing — backup and cron jobs cannot run. Run install-panel.sh or php artisan panel:cron:sync as root.",
            );
        }

        return $this->check('cron_file', 'Cron file', 'ok', "{$file} is installed.");
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    private function backupSchedulesCheck(): array
    {
        $schedules = BackupSchedule::where('enabled', true)->orderBy('id')->get();

        if ($schedules->isEmpty()) {
            return $this->check('backups', 'Backups', 'ok', 'No backup schedules yet.');
        }

        $overdue = $schedules
            ->filter(fn (BackupSchedule $schedule) => $schedule->next_run_at !== null
                && $schedule->next_run_at->lte(now()->subMinutes(self::OVERDUE_MINUTES)));

        if ($overdue->isNotEmpty()) {
            $first = $overdue->first();

            return $this->check(
                'backups',
                'Backups',
                'fail',
                "Scheduler idle: {$overdue->count()} schedule(s) overdue — last due at {$first->next_run_at->format('M d, H:i')} (cron may not be running).",
            );
        }

        $failed = $schedules->where('last_status', 'failed');

        if ($failed->isNotEmpty()) {
            $first = $failed->first();

            return $this->check(
                'backups',
                'Backups',
                'warn',
                "Last run failed for {$first->targetLabel()}: ".mb_substr((string) $first->last_error, 0, 200),
            );
        }

        $neverRun = $schedules->whereNull('last_run_at');
        $next = $schedules->whereNotNull('next_run_at')->sortBy('next_run_at')->first();

        return $this->check(
            'backups',
            'Backups',
            'ok',
            sprintf(
                '%d schedule(s) healthy%s%s.',
                $schedules->count(),
                $neverRun->isNotEmpty() ? ' — first run pending' : '',
                $next !== null ? ', next at '.$next->next_run_at->format('M d, H:i') : '',
            ),
        );
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    private function cronJobsCheck(): array
    {
        $jobs = CronJob::where('enabled', true)->orderBy('id')->get();

        if ($jobs->isEmpty()) {
            return $this->check('cron_jobs', 'Cron jobs', 'ok', 'No cron jobs yet.');
        }

        $failed = $jobs->where('last_status', 'failed');

        if ($failed->isNotEmpty()) {
            $first = $failed->first();

            return $this->check(
                'cron_jobs',
                'Cron jobs',
                'warn',
                "“{$first->name}” last failed: ".mb_substr((string) $first->last_output, 0, 200),
            );
        }

        return $this->check('cron_jobs', 'Cron jobs', 'ok', "{$jobs->count()} enabled job(s), all healthy.");
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    private function diskCheck(): array
    {
        $dir = (string) config('panel.backup.dir');

        if (! is_dir($dir)) {
            $dir = '/';
        }

        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);

        if ($free === false || $total === false || $total <= 0) {
            return $this->check('disk_space', 'Backup disk space', 'warn', 'Could not read free disk space.');
        }

        $freeGb = $free / 1024 ** 3;
        $totalGb = $total / 1024 ** 3;
        $percentFree = $free / $total * 100;
        $detail = sprintf('%.1f GB free of %.1f GB (%.0f%% free).', $freeGb, $totalGb, $percentFree);

        if ($percentFree < 3) {
            return $this->check('disk_space', 'Backup disk space', 'fail', $detail.' Backups will fail soon — free space or lower retention.');
        }

        if ($percentFree < 10) {
            return $this->check('disk_space', 'Backup disk space', 'warn', $detail);
        }

        return $this->check('disk_space', 'Backup disk space', 'ok', $detail);
    }

    /**
     * @return array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    private function check(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
