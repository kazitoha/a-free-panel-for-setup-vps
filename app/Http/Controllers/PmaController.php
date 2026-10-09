<?php

namespace App\Http\Controllers;

use App\Models\Install;
use App\Services\InstallRunner;
use App\Services\SudoRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PmaController extends Controller
{
    public function __construct(
        private readonly InstallRunner $runner,
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
        $active = Install::ofType('pma')
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->exists();

        if ($active) {
            return redirect()->route('installs.show', Install::ofType('pma')->latest('id')->firstOrFail());
        }

        $install = Install::create([
            'type' => 'pma',
            'label' => 'Install phpMyAdmin',
            'command' => 'panel:pma:install',
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
