<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidMailRecord;
use App\Exceptions\PowerDnsError;
use App\Exceptions\PowerDnsUnavailable;
use App\Exceptions\StalwartError;
use App\Models\Install;
use App\Services\InstallRunner;
use App\Services\MailService;
use App\Services\SudoRunner;
use App\Services\SystemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class MailController extends Controller
{
    public function __construct(
        private readonly MailService $mail,
        private readonly InstallRunner $runner,
        private readonly SystemService $system,
    ) {}

    public function index(): View
    {
        $latest = Install::ofType('stalwart')->latest('id')->first();
        $active = $latest?->isActive() ? $latest : null;

        $status = $active ? 'installing' : $this->mail->status();

        return view('mail.index', [
            'status' => $status,
            'domains' => $status === 'ok' ? $this->mail->domains() : [],
            'hostname' => $this->mail->hostname(),
            'webUrl' => 'https://'.$this->mail->hostname(),
            'active' => $active,
            'latest' => $latest,
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        $active = Install::ofType('stalwart')
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->exists();

        if ($active) {
            return redirect()->route('installs.show', Install::ofType('stalwart')->latest('id')->firstOrFail());
        }

        $install = Install::create([
            'type' => 'stalwart',
            'label' => 'Install Stalwart mail',
            'command' => 'panel:stalwart:install',
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

    public function create(): View|RedirectResponse
    {
        if ($this->mail->status() !== 'ok') {
            return redirect()->route('mail.index');
        }

        return view('mail.create', [
            'hostname' => $this->mail->hostname(),
            'defaultIp' => $this->defaultIp(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->mail->status() !== 'ok') {
            return redirect()
                ->route('mail.index')
                ->with('error', 'Stalwart is not reachable — install or start it first.');
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'ip' => ['nullable', 'string', 'max:45'],
        ], [], ['domain' => 'domain', 'ip' => 'server IP']);

        $ip = trim((string) ($validated['ip'] ?? '')) ?: null;

        try {
            $id = $this->mail->createDomain($validated['domain'], $ip);
        } catch (InvalidMailRecord $exception) {
            return back()->withErrors($exception->errors)->withInput();
        } catch (PowerDnsUnavailable) {
            return back()->withInput()->with(
                'error',
                'PowerDNS is not reachable — it is required to publish MX/SPF/DMARC/DKIM records.',
            );
        } catch (PowerDnsError|StalwartError $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('mail.show', $id)
            ->with('success', "Mail domain {$validated['domain']} created — MX/SPF/DMARC/DKIM records publish automatically.");
    }

    public function show(string $domain): View|RedirectResponse
    {
        try {
            $data = $this->mail->domain($domain);
        } catch (StalwartError $exception) {
            return redirect()
                ->route('mail.index')
                ->with('error', $exception->getMessage());
        }

        if ($data === null) {
            return redirect()
                ->route('mail.index')
                ->with('error', 'That mail domain no longer exists.');
        }

        try {
            $mailboxes = $this->mail->mailboxes($data['id']);
        } catch (StalwartError $exception) {
            return redirect()
                ->route('mail.index')
                ->with('error', $exception->getMessage());
        }

        return view('mail.show', [
            'domain' => $data,
            'mailboxes' => $mailboxes,
            'webUrl' => 'https://'.$this->mail->hostname(),
        ]);
    }

    public function destroy(string $domain): RedirectResponse
    {
        if ($this->mail->status() !== 'ok') {
            return redirect()
                ->route('mail.index')
                ->with('error', 'Stalwart is not reachable.');
        }

        try {
            $this->mail->deleteDomain($domain);
        } catch (StalwartError $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('mail.index')
            ->with('success', 'Mail domain deleted.');
    }

    private function defaultIp(): string
    {
        return (string) (config('panel.pdns.server_ip') ?: $this->system->publicIp() ?: '');
    }
}
