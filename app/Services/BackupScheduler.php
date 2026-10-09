<?php

namespace App\Services;

use App\Models\BackupSchedule;
use Throwable;

/**
 * Executes a backup schedule: resolves its target, runs the matching backup,
 * records success/failure on the schedule row, and handles the missing-target
 * case (auto-disable) that happens when the database/project was deleted.
 */
class BackupScheduler
{
    public function __construct(private readonly BackupService $backups) {}

    /**
     * @return array{status: 'success'|'failed', message: string, path: ?string}
     */
    public function runSchedule(BackupSchedule $schedule): array
    {
        $target = $schedule->target();

        if ($target === null) {
            $schedule->update([
                'enabled' => false,
                'next_run_at' => null,
                'last_run_at' => now(),
                'last_status' => 'failed',
                'last_error' => 'The backup target no longer exists — the schedule was disabled.',
                'last_path' => null,
            ]);

            return [
                'status' => 'failed',
                'message' => "Target for schedule #{$schedule->id} no longer exists; schedule disabled.",
                'path' => null,
            ];
        }

        try {
            $path = match ($schedule->target_type) {
                BackupSchedule::TYPE_DATABASE => $this->backups->backupDatabase($target, $schedule->retention_days),
                BackupSchedule::TYPE_PROJECT => $this->backups->backupProject($target, $schedule->retention_days),
                default => throw new \RuntimeException("Unknown backup target type: {$schedule->target_type}"),
            };
        } catch (Throwable $exception) {
            $schedule->update([
                'last_run_at' => now(),
                'last_status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'last_path' => null,
            ]);

            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'path' => null,
            ];
        }

        $size = is_file($path) ? (int) filesize($path) : 0;

        $schedule->update([
            'last_run_at' => now(),
            'last_status' => 'success',
            'last_error' => null,
            'last_path' => $path,
        ]);

        return [
            'status' => 'success',
            'message' => "Saved {$path} (".$this->humanSize($size).')',
            'path' => $path,
        ];
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1).' MB';
        }

        return number_format($bytes / 1024 / 1024 / 1024, 2).' GB';
    }
}
