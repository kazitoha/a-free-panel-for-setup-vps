<?php

namespace App\Http\Controllers;

use App\Models\BackupSchedule;
use App\Models\Database;
use App\Models\Project;
use App\Services\SudoRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BackupController extends Controller
{
    public function __construct(private readonly SudoRunner $sudo) {}

    public function index(): View
    {
        $databaseNames = Database::pluck('name', 'id');
        $projectNames = Project::pluck('name', 'id');

        $schedules = BackupSchedule::orderBy('id')->get()->map(function (BackupSchedule $schedule) use ($databaseNames, $projectNames) {
            $names = $schedule->target_type === BackupSchedule::TYPE_DATABASE ? $databaseNames : $projectNames;

            return [
                'schedule' => $schedule,
                'label' => $names->get($schedule->target_id) ?? "target #{$schedule->target_id}",
                'missing' => $names->get($schedule->target_id) === null,
            ];
        });

        $taken = BackupSchedule::query()
            ->get(['target_type', 'target_id'])
            ->groupBy('target_type')
            ->map(fn ($rows) => $rows->pluck('target_id')->map(fn ($id) => (int) $id)->all());

        $targets = collect()
            ->concat($databaseNames->map(fn ($name, $id) => [
                'value' => BackupSchedule::TYPE_DATABASE.':'.$id,
                'label' => "Database · {$name}",
            ])->values())
            ->concat($projectNames->map(fn ($name, $id) => [
                'value' => BackupSchedule::TYPE_PROJECT.':'.$id,
                'label' => "Project · {$name}",
            ])->values())
            ->reject(fn (array $target) => in_array(
                (int) explode(':', $target['value'])[1],
                $taken[explode(':', $target['value'])[0]] ?? [],
                true,
            ))
            ->values();

        $files = $this->backupList();

        return view('backups.index', [
            'schedules' => $schedules,
            'targets' => $targets,
            'files' => $files,
            'filesError' => $files === null
                ? 'Could not read the backup directory — run install-panel.sh if this persists.'
                : null,
            'retentionDefault' => (int) config('panel.backup.retention_days'),
            'storageDir' => (string) config('panel.backup.dir'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'target' => ['required', 'string'],
            'times_per_day' => ['required', 'integer', 'min:1', 'max:24'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $parts = explode(':', (string) $request->input('target'), 2);
        $type = $parts[0] ?? '';
        $targetId = isset($parts[1]) ? (int) $parts[1] : 0;

        if (! in_array($type, [BackupSchedule::TYPE_DATABASE, BackupSchedule::TYPE_PROJECT], true) || $targetId < 1) {
            return back()->withErrors(['target' => 'Choose a database or project to back up.']);
        }

        $exists = $type === BackupSchedule::TYPE_DATABASE
            ? Database::whereKey($targetId)->exists()
            : Project::whereKey($targetId)->exists();

        if (! $exists) {
            return back()->withErrors(['target' => 'That target no longer exists.'])->onlyInput('target');
        }

        if (BackupSchedule::where('target_type', $type)->where('target_id', $targetId)->exists()) {
            return back()->withErrors(['target' => 'That target already has a schedule.'])->onlyInput('target');
        }

        $schedule = new BackupSchedule([
            'target_type' => $type,
            'target_id' => $targetId,
            'times_per_day' => (int) $request->input('times_per_day'),
            'retention_days' => (int) $request->input('retention_days'),
            'enabled' => true,
        ]);
        $schedule->next_run_at = $schedule->computeNextRunAt();
        $schedule->save();

        return back()->with('success', "Schedule created — first run at {$schedule->next_run_at->format('D M d, H:i')}.");
    }

    public function run(BackupSchedule $schedule): RedirectResponse
    {
        $result = $this->sudo->run(['panel:backup:run', (string) $schedule->id], 600.0);

        if ($result['exit'] !== 0) {
            return back()->with('error', $this->failureMessage('run the backup', $result));
        }

        $schedule->refresh();
        $output = trim($result['stdout']);

        return back()->with(
            'success',
            $output !== '' ? $output : "Backup completed for {$schedule->targetLabel()}.",
        );
    }

    public function toggle(BackupSchedule $schedule): RedirectResponse
    {
        $enabled = ! $schedule->enabled;

        $schedule->update([
            'enabled' => $enabled,
            'next_run_at' => $enabled ? $schedule->computeNextRunAt() : null,
        ]);

        return back()->with(
            'success',
            $enabled
                ? "Schedule enabled — next run at {$schedule->next_run_at->format('D M d, H:i')}."
                : 'Schedule paused.',
        );
    }

    public function destroy(BackupSchedule $schedule): RedirectResponse
    {
        $schedule->delete();

        return back()->with('success', 'Schedule removed — existing archives are kept.');
    }

    /**
     * @return list<array{scope: string, file: string, size: int, mtime: int}>|null
     */
    private function backupList(): ?array
    {
        $result = $this->sudo->run(['panel:backup:list'], 60.0);

        if ($result['exit'] !== 0) {
            return null;
        }

        $decoded = json_decode(trim($result['stdout']), true);

        if (! is_array($decoded)) {
            return null;
        }

        $rows = [];

        foreach (['databases', 'projects'] as $scope) {
            foreach ($decoded[$scope] ?? [] as $file) {
                if (is_array($file)) {
                    $size = (int) ($file['size'] ?? 0);
                    $rows[] = ['scope' => $scope] + $file + ['size_label' => self::humanSize($size)];
                }
            }
        }

        usort($rows, fn (array $a, array $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));

        return $rows;
    }

    public static function humanSize(int $bytes): string
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

    /**
     * @param  array{exit: int, stdout: string, stderr: string, denied: bool}  $result
     */
    private function failureMessage(string $action, array $result): string
    {
        if ($result['denied']) {
            return SudoRunner::denialMessage();
        }

        $detail = trim($result['stderr'].$result['stdout']);
        $detail = $detail !== '' ? mb_substr($detail, 0, 300) : "The command exited with code {$result['exit']}.";

        return "Could not {$action}: {$detail}";
    }
}
