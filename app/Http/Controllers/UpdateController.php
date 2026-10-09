<?php

namespace App\Http\Controllers;

use App\Models\Install;
use App\Services\InstallRunner;
use App\Services\SudoRunner;
use App\Services\UpdateService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class UpdateController extends Controller
{
    public function __construct(
        private readonly UpdateService $updates,
        private readonly InstallRunner $runner,
    ) {}

    /**
     * Force a fresh check through the root-side runner, then flash the result.
     */
    public function check(): RedirectResponse
    {
        $status = $this->updates->refresh();

        if ($status['denied']) {
            return back()->with('error', SudoRunner::denialMessage());
        }

        if ($status['error'] !== null) {
            return back()->with('error', 'Could not check for updates: '.$status['error']);
        }

        if ($status['update_available']) {
            return back()->with('success', "Update available — {$status['behind']} commit(s) behind.");
        }

        return back()->with('success', 'The panel is up to date.');
    }

    /**
     * Apply the pending update in the background and follow its install log.
     */
    public function run(): RedirectResponse
    {
        $active = Install::ofType('update')
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->exists();

        if ($active) {
            return redirect()->route('installs.show', Install::ofType('update')->latest('id')->firstOrFail());
        }

        $install = Install::create([
            'type' => 'update',
            'label' => 'Apply panel update',
            'command' => 'panel:update:run',
            'status' => Install::STATUS_PENDING,
        ]);

        try {
            $this->runner->start($install);
        } catch (RuntimeException $exception) {
            $install->forceFill([
                'status' => Install::STATUS_FAILED,
                'message' => mb_substr($exception->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            $message = $exception->getMessage();

            if (str_contains($message, 'sudo')) {
                $message = SudoRunner::denialMessage();
            }

            return back()->with('error', $message);
        }

        return redirect()->route('installs.show', $install);
    }
}
