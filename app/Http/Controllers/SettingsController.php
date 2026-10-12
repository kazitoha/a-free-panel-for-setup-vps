<?php

namespace App\Http\Controllers;

use App\Models\Install;
use App\Services\InstallStarter;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

/**
 * Panel settings. Right now it exposes one thing: the Reset danger zone,
 * which uninstalls every module and wipes the panel back to /first-run.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly InstallStarter $starter) {}

    public function index(): View
    {
        $latest = Install::ofType('reset')->latest('id')->first();

        return view('settings', [
            'domain' => (string) config('panel.domain'),
            'active' => $latest?->isActive() ? $latest : null,
            'latest' => $latest,
        ]);
    }

    public function reset(): RedirectResponse
    {
        try {
            $install = $this->starter->start('reset', 'Reset the panel', 'panel:reset');
        } catch (RuntimeException $exception) {
            return back()->with('error', InstallStarter::failureMessage($exception));
        }

        return redirect()->route('installs.show', $install);
    }
}
