<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidCron;
use App\Models\CronJob;
use App\Services\SudoRunner;
use App\Support\CronSpec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CronController extends Controller
{
    public function __construct(private readonly SudoRunner $sudo) {}

    public function index(): View
    {
        return view('cron.index', [
            'jobs' => CronJob::orderBy('id')->get(),
            'frequencies' => CronSpec::frequencyOptions(),
            'weekdays' => CronSpec::weekdayOptions(),
            'cronFile' => (string) config('panel.cron_file'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1F#]/'],
            'command' => ['required', 'string', 'max:300'],
            'frequency' => ['required', 'string'],
            'time' => ['required', 'string'],
            'every_hours' => ['nullable', 'string'],
            'weekday' => ['nullable', 'string'],
        ]);

        try {
            $command = CronSpec::validateCommand((string) $request->input('command'));
            $fields = CronSpec::fieldsFromRequest(
                (string) $request->input('frequency'),
                (string) $request->input('time'),
                $request->input('every_hours') !== null ? (string) $request->input('every_hours') : null,
                $request->input('weekday') !== null ? (string) $request->input('weekday') : null,
            );
        } catch (InvalidCron $exception) {
            return back()->withErrors($exception->errors)->withInput();
        }

        $job = CronJob::create($fields + [
            'name' => trim((string) $request->input('name')),
            'command' => $command,
            'enabled' => true,
        ]);

        if ($syncError = $this->sync()) {
            return back()->withInput()
                ->with('success', "Cron job #{$job->id} saved.")
                ->with('error', $syncError);
        }

        return back()->with('success', "Cron job “{$job->name}” saved and installed.");
    }

    public function toggle(CronJob $job): RedirectResponse
    {
        $job->update(['enabled' => ! $job->enabled]);

        $syncError = $this->sync();

        $message = $job->enabled ? "“{$job->name}” enabled." : "“{$job->name}” disabled.";

        return $syncError !== null
            ? back()->with('success', $message)->with('error', $syncError)
            : back()->with('success', $message);
    }

    public function run(CronJob $job): RedirectResponse
    {
        $result = $this->sudo->run(['panel:cron:exec', (string) $job->id], 300.0);

        if ($result['denied']) {
            return back()->with('error', SudoRunner::denialMessage());
        }

        $job->refresh();
        $stdout = trim($result['stdout']);

        if ($result['exit'] === 0) {
            return back()->with(
                'success',
                $stdout !== '' ? mb_substr($stdout, 0, 500) : "“{$job->name}” finished (exit 0).",
            );
        }

        $detail = trim((string) ($job->last_output ?? ''));

        if ($detail === '') {
            $detail = trim($result['stderr']);
        }

        if ($detail === '') {
            $detail = "The command exited with code {$result['exit']}.";
        }

        return back()->with('error', "Could not run “{$job->name}”: ".mb_substr($detail, 0, 500));
    }

    public function destroy(CronJob $job): RedirectResponse
    {
        $name = $job->name;
        $job->delete();

        $syncError = $this->sync();

        $message = "Cron job “{$name}” removed.";

        return $syncError !== null
            ? back()->with('success', $message)->with('error', $syncError)
            : back()->with('success', $message);
    }

    /**
     * Ask the root runner to rewrite /etc/cron.d/panel. Returns an error
     * message when the rewrite failed, or null on success.
     */
    private function sync(): ?string
    {
        $result = $this->sudo->run(['panel:cron:sync'], 60.0);

        if ($result['exit'] === 0) {
            return null;
        }

        if ($result['denied']) {
            return SudoRunner::denialMessage();
        }

        $detail = trim($result['stderr'].$result['stdout']);
        $detail = $detail !== '' ? mb_substr($detail, 0, 300) : "the command exited with code {$result['exit']}";

        return "The job was saved, but /etc/cron.d/panel could not be updated: {$detail}";
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
