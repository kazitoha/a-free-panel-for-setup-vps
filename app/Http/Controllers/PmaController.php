<?php

namespace App\Http\Controllers;

use App\Models\Install;
use App\Services\InstallStarter;
use App\Services\SudoRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PmaController extends Controller
{
    public function __construct(
        private readonly InstallStarter $starter,
        private readonly SudoRunner $sudo,
    ) {}

    public function index(): View
    {
        $dir = (string) config('panel.phpmyadmin.dir');

        $latest = Install::ofType('pma')->latest('id')->first();
        $active = $latest?->isActive() ? $latest : null;

        return view('phpmyadmin', [
            'installed' => is_file($dir.'/index.php'),
            'dir' => $dir,
            'url' => 'https://pma.'.config('panel.domain'),
            'phpVersion' => config('panel.php'),
            'active' => $active,
            'latest' => $latest,
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        try {
            $install = $this->starter->start('pma', 'Install phpMyAdmin', 'panel:pma:install');
        } catch (RuntimeException $exception) {
            return back()->with('error', InstallStarter::failureMessage($exception));
        }

        return redirect()->route('installs.show', $install);
    }

    public function uninstall(): RedirectResponse
    {
        $result = $this->sudo->run(['panel:pma:uninstall'], 120);

        if ($result['exit'] === 0) {
            return back()->with('success', 'phpMyAdmin removed.');
        }

        if ($result['denied']) {
            return back()->with('error', SudoRunner::denialMessage());
        }

        $detail = trim($result['stdout'].$result['stderr']);
        $detail = $detail !== '' ? mb_substr($detail, 0, 300) : "The command exited with code {$result['exit']}.";

        return back()->with('error', "Could not uninstall phpMyAdmin: {$detail}");
    }
}
