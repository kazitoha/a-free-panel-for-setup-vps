<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidDnsRecord;
use App\Exceptions\PowerDnsError;
use App\Exceptions\PowerDnsUnavailable;
use App\Models\Install;
use App\Services\DnsService;
use App\Services\InstallStarter;
use App\Services\SystemService;
use App\Support\DnsSpec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class DnsController extends Controller
{
    public function __construct(
        private readonly DnsService $dns,
        private readonly SystemService $system,
        private readonly InstallStarter $starter,
    ) {}

    public function index(): View
    {
        $status = $this->dns->status();
        $latest = Install::ofType('pdns')->latest('id')->first();

        return view('dns.index', [
            'status' => $status,
            'zones' => $status === 'ok' ? $this->dns->zones() : [],
            'nsDomain' => $this->nsDomain(),
            'defaultIp' => $this->defaultIp(),
            'active' => $latest?->isActive() ? $latest : null,
            'latest' => $latest,
        ]);
    }

    /**
     * Install PowerDNS (and MySQL first, when it is missing): the DNS
     * module's own first step.
     */
    public function install(): RedirectResponse
    {
        try {
            $install = $this->starter->start('pdns', 'Install PowerDNS', 'panel:pdns:install');
        } catch (RuntimeException $exception) {
            return back()->with('error', InstallStarter::failureMessage($exception));
        }

        return redirect()->route('installs.show', $install);
    }

    public function create(): View|RedirectResponse
    {
        if ($this->dns->status() !== 'ok') {
            return redirect()->route('dns.index');
        }

        return view('dns.create', [
            'defaultIp' => $this->defaultIp(),
            'nsDomain' => $this->nsDomain(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($unavailable = $this->requireApi()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'ip' => ['nullable', 'string', 'max:45'],
            'wildcard' => ['nullable'],
        ], [], [
            'domain' => 'domain',
            'ip' => 'server IP',
        ]);

        try {
            $this->dns->createZone(
                $validated['domain'],
                trim((string) ($validated['ip'] ?? '')) ?: null,
                $request->boolean('wildcard'),
            );
        } catch (InvalidDnsRecord $exception) {
            return back()->withErrors($exception->errors)->withInput();
        } catch (PowerDnsError $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('dns.show', DnsSpec::zoneName($validated['domain']))
            ->with('success', "Zone {$validated['domain']} created.");
    }

    public function import(Request $request): RedirectResponse
    {
        if ($unavailable = $this->requireApi()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'domains' => ['required', 'string', 'max:10000'],
            'ip' => ['nullable', 'string', 'max:45'],
            'wildcard' => ['nullable'],
        ], [], ['domains' => 'domain list']);

        $domains = preg_split('/[\s,;]+/', trim($validated['domains'])) ?: [];
        $domains = array_values(array_unique(array_filter($domains)));

        if (count($domains) > 200) {
            return back()->withErrors(['domains' => 'Import at most 200 domains at a time.'])->withInput();
        }

        $result = $this->dns->importZones(
            $domains,
            trim((string) ($validated['ip'] ?? '')) ?: null,
            $request->boolean('wildcard'),
        );

        $message = count($result['created']).' zone(s) created.';

        if ($result['failed'] !== []) {
            $details = array_map(
                fn (array $failure): string => $failure['domain'].': '.$failure['error'],
                $result['failed'],
            );
            $message .= ' '.count($result['failed']).' failed — '.implode('; ', array_slice($details, 0, 5));
        }

        return redirect()
            ->route('dns.index')
            ->with($result['failed'] === [] ? 'success' : 'error', $message);
    }

    public function show(string $zone): View|RedirectResponse
    {
        try {
            $data = $this->dns->zone($zone);
        } catch (PowerDnsUnavailable) {
            return redirect()
                ->route('dns.index')
                ->with('error', 'PowerDNS is not reachable — the API is unavailable.');
        } catch (PowerDnsError $exception) {
            return redirect()
                ->route('dns.index')
                ->with('error', "Zone {$zone}: {$exception->getMessage()}");
        }

        return view('dns.show', [
            'zone' => $data,
            'types' => DnsSpec::TYPES,
        ]);
    }

    public function destroy(string $zone): RedirectResponse
    {
        if ($unavailable = $this->requireApi()) {
            return $unavailable;
        }

        try {
            $this->dns->deleteZone($zone);
        } catch (InvalidDnsRecord $exception) {
            return back()->with('error', implode(' ', $exception->errors));
        } catch (PowerDnsError $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('dns.index')
            ->with('success', "Zone {$zone} deleted.");
    }

    private function requireApi(): ?RedirectResponse
    {
        if ($this->dns->status() !== 'ok') {
            return redirect()
                ->route('dns.index')
                ->with('error', 'PowerDNS is not reachable — the API is unavailable.');
        }

        return null;
    }

    private function nsDomain(): string
    {
        return (string) (config('panel.pdns.ns_domain') ?: config('panel.domain'));
    }

    private function defaultIp(): string
    {
        return (string) (config('panel.pdns.server_ip') ?: $this->system->publicIp() ?: '');
    }
}
