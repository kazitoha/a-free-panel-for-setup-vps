<?php

namespace App\Http\Controllers;

use App\Models\Install;
use App\Services\InstallStarter;
use App\Services\PhpVersionService;
use App\Services\SudoRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PhpController extends Controller
{
    public function __construct(
        private readonly PhpVersionService $versions,
        private readonly InstallStarter $starter,
        private readonly SudoRunner $sudo,
    ) {}

    public function index(): View
    {
        return view('php', [
            'installed' => $this->versions->installed(),
            'installable' => $this->versions->installable(),
            'panelPhp' => (string) config('panel.php'),
            'active' => $this->activeInstall(),
            'latestFailed' => Install::ofType('php')
                ->where('status', Install::STATUS_FAILED)
                ->latest('id')
                ->first(),
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        $version = $this->validatedVersion($request);

        if (in_array($version, $this->versions->installed(), true)) {
            return back()->with('success', "PHP {$version} is already installed.");
        }

        if ($this->activeInstall() !== null) {
            return redirect()->route('installs.show', $this->activeInstall());
        }

        try {
            $install = $this->starter->start(
                'php',
                "Install PHP {$version}",
                "panel:php:install --php={$version}",
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', InstallStarter::failureMessage($exception));
        }

        return redirect()->route('installs.show', $install);
    }

    public function remove(Request $request): RedirectResponse
    {
        $version = $this->validatedVersion($request);

        if ($version === (string) config('panel.php')) {
            return back()->with('error', "PHP {$version} runs the panel itself — it cannot be removed.");
        }

        if (! $this->versions->removable($version)) {
            return back()->with('error', "PHP {$version} is not installed.");
        }

        $result = $this->sudo->run(['panel:php:remove', "--php={$version}"], 600.0);

        if ($result['exit'] !== 0) {
            $message = $result['denied']
                ? SudoRunner::denialMessage()
                : trim($result['stderr'].$result['stdout']);

            return back()->with('error', $message !== '' ? $message : "Could not remove PHP {$version}.");
        }

        return back()->with('success', "PHP {$version} removed.");
    }

    private function validatedVersion(Request $request): string
    {
        return (string) $request->validate([
            'version' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
        ])['version'];
    }

    private function activeInstall(): ?Install
    {
        return Install::ofType('php')
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->latest('id')
            ->first();
    }
}
