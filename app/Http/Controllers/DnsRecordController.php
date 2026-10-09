<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidDnsRecord;
use App\Exceptions\PowerDnsError;
use App\Exceptions\PowerDnsUnavailable;
use App\Services\DnsService;
use App\Support\DnsSpec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DnsRecordController extends Controller
{
    public function __construct(private readonly DnsService $dns) {}

    public function store(Request $request, string $zone): RedirectResponse
    {
        $input = $this->recordInput($request, $zone);

        if ($input instanceof RedirectResponse) {
            return $input;
        }

        if ($redirect = $this->guard($zone)) {
            return $redirect;
        }

        try {
            $this->dns->addRecord($zone, $input);
        } catch (InvalidDnsRecord $exception) {
            return back()->withErrors($exception->errors)->withInput();
        } catch (PowerDnsError $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', 'Record added — serial bumped by PowerDNS.');
    }

    public function update(Request $request, string $zone): RedirectResponse
    {
        $input = $this->recordInput($request, $zone);

        if ($input instanceof RedirectResponse) {
            return $input;
        }

        if ($redirect = $this->guard($zone)) {
            return $redirect;
        }

        $current = [
            'name' => (string) $request->input('old_name'),
            'type' => (string) $request->input('old_type'),
            'content' => (string) $request->input('old_content'),
        ];

        try {
            $this->dns->updateRecord($zone, $current, $input);
        } catch (InvalidDnsRecord $exception) {
            return back()->withErrors($exception->errors)->withInput();
        } catch (PowerDnsError $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', 'Record updated.');
    }

    public function destroy(Request $request, string $zone): RedirectResponse
    {
        if ($redirect = $this->guard($zone)) {
            return $redirect;
        }

        try {
            $this->dns->deleteRecord($zone, [
                'name' => (string) $request->input('name'),
                'type' => (string) $request->input('type'),
                'content' => (string) $request->input('content'),
            ]);
        } catch (InvalidDnsRecord $exception) {
            return back()->with('error', implode(' ', $exception->errors));
        } catch (PowerDnsError $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Record deleted.');
    }

    private function guard(string $zone): ?RedirectResponse
    {
        try {
            if ($this->dns->status() !== 'ok') {
                return redirect()
                    ->route('dns.index')
                    ->with('error', 'PowerDNS is not reachable — the API is unavailable.');
            }

            if (! $this->dns->zoneExists($zone)) {
                return redirect()
                    ->route('dns.index')
                    ->with('error', "Zone {$zone} no longer exists.");
            }
        } catch (PowerDnsUnavailable) {
            return redirect()
                ->route('dns.index')
                ->with('error', 'PowerDNS is not reachable — the API is unavailable.');
        } catch (PowerDnsError $exception) {
            return redirect()
                ->route('dns.index')
                ->with('error', $exception->getMessage());
        }

        return null;
    }

    /**
     * @return array{name: string, type: string, content: string, ttl: int}|RedirectResponse
     */
    private function recordInput(Request $request, string $zone): array|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:10'],
            'content' => ['required', 'string', 'max:4096'],
            'ttl' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            return DnsSpec::record($zone, $validated);
        } catch (InvalidDnsRecord $exception) {
            return back()->withErrors($exception->errors)->withInput();
        }
    }
}
