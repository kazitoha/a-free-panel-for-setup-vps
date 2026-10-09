@extends('layouts.app')

@section('title', 'Email')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">stalwart · mail</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Email</h1>
            <p class="mt-1 max-w-xl text-sm text-slate-500">
                Stalwart mail server with SMTP, IMAP and JMAP. Certificates via
                DNS-01, MX/SPF/DMARC/DKIM published automatically through PowerDNS.
            </p>
        </div>
        @if ($status === 'ok')
            <a href="{{ route('mail.create') }}" class="btn-primary">＋ New mail domain</a>
        @endif
    </div>

    @if ($status === 'installing')
        <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <p class="eyebrow">installing</p>
                    <span class="badge badge-amber">in progress</span>
                </div>
                <div class="mt-4 space-y-3">
                    <div class="flex items-center justify-between rounded-md border border-slate-800 bg-[#0a101c] p-3">
                        <div>
                            <p class="text-sm text-slate-300">{{ $active->label }}…</p>
                            <p class="font-mono text-[11px] text-slate-600">started {{ $active->created_at->diffForHumans() }}</p>
                        </div>
                        <a href="{{ route('installs.show', $active) }}" class="btn-ghost">Watch ↗</a>
                    </div>
                    <p class="text-xs leading-relaxed text-slate-500">
                        Downloads the official installer, finishes the setup wizard,
                        moves the HTTP listener behind Caddy and opens the mail ports.
                    </p>
                </div>
            </div>

            <div class="card p-5">
                <p class="eyebrow">what you get</p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Web admin</dt>
                        <dd class="font-mono text-slate-300">{{ $webUrl }}/admin</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Mail host</dt>
                        <dd class="font-mono text-slate-300">{{ $hostname }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ports</dt>
                        <dd class="font-mono text-slate-300">25 · 465 · 993 · 995 · 4190</dd>
                    </div>
                </dl>
            </div>
        </div>
    @elseif ($status === 'unreachable')
        <div class="card p-6">
            <p class="eyebrow">stalwart not reachable</p>
            <p class="mt-2 max-w-2xl text-sm text-slate-400">
                JMAP did not answer on
                <span class="font-mono text-slate-300">{{ config('panel.stalwart.url') }}</span>.
                If this server has never had Stalwart, press install below; otherwise check
                <span class="font-mono text-slate-300">systemctl status stalwart</span>.
            </p>
            @if (! $active)
                <form method="POST" action="{{ route('mail.install') }}" class="mt-4"
                      onsubmit="return confirm('Install Stalwart mail now?');">
                    @csrf
                    <button type="submit" class="btn-primary">⬇ Install Stalwart</button>
                </form>
            @endif
        </div>
    @elseif ($status === 'unauthorized')
        <div class="card p-6">
            <p class="eyebrow">credentials rejected</p>
            <p class="mt-2 max-w-2xl text-sm text-slate-400">
                Stalwart answered but rejected
                <span class="font-mono text-amber-400">STALWART_USER</span> /
                <span class="font-mono text-amber-400">STALWART_PASSWORD</span> from
                <span class="font-mono text-slate-300">.env</span>. Press install to re-run
                the repair flow — it verifies the panel credentials and falls back to the
                recovery administrator pinned in <span class="font-mono text-slate-300">/etc/stalwart/stalwart.env</span>.
            </p>
            @if (! $active)
                <form method="POST" action="{{ route('mail.install') }}" class="mt-4"
                      onsubmit="return confirm('Re-run the Stalwart repair flow?');">
                    @csrf
                    <button type="submit" class="btn-primary">↻ Repair credentials</button>
                </form>
            @endif
        </div>
    @elseif ($status === 'bootstrap')
        <div class="card p-6">
            <p class="eyebrow">setup not finished</p>
            <p class="mt-2 max-w-2xl text-sm text-slate-400">
                Stalwart is running in recovery mode — the setup wizard has not been completed.
                Run the install flow to finish it (hostname, default domain, PowerDNS provider,
                DKIM and ACME), then reload this page.
            </p>
            @if ($active)
                <div class="mt-4 flex items-center justify-between rounded-md border border-slate-800 bg-[#0a101c] p-3">
                    <div>
                        <p class="text-sm text-slate-300">{{ $active->label }}…</p>
                        <p class="font-mono text-[11px] text-slate-600">started {{ $active->created_at->diffForHumans() }}</p>
                    </div>
                    <a href="{{ route('installs.show', $active) }}" class="btn-ghost">Watch ↗</a>
                </div>
            @else
                <form method="POST" action="{{ route('mail.install') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn-primary">▶ Complete setup</button>
                </form>
            @endif
        </div>
    @elseif ($status === 'error')
        <div class="card p-6">
            <p class="eyebrow">jmap error</p>
            <p class="mt-2 max-w-2xl text-sm text-slate-400">
                The API answered with an unexpected error. Check
                <span class="font-mono text-slate-300">journalctl -u stalwart -e</span>
                and <span class="font-mono text-slate-300">panel:stalwart:install {id}</span>
                logs for details.
            </p>
        </div>
    @else
        {{-- Status + actions --}}
        <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <p class="eyebrow">status</p>
                    <span class="badge badge-green">running</span>
                </div>

                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Web admin</dt>
                        <dd class="font-mono text-slate-300">
                            <a href="{{ $webUrl }}/admin" target="_blank" rel="noopener" class="text-amber-400 hover:underline">
                                {{ $webUrl }}/admin ↗
                            </a>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Mail host</dt>
                        <dd class="font-mono text-slate-300">{{ $hostname }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Domains</dt>
                        <dd class="font-mono text-slate-300">{{ count($domains) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ports</dt>
                        <dd class="font-mono text-slate-300">25 · 465 · 993 · 995 · 4190</dd>
                    </div>
                </dl>
            </div>

            <div class="card p-5">
                <p class="eyebrow">actions</p>
                <div class="mt-4 space-y-3">
                    <a href="{{ $webUrl }}/admin" target="_blank" rel="noopener"
                       class="btn-primary w-full justify-center">Open Stalwart admin ↗</a>
                    <a href="{{ route('mail.create') }}"
                       class="btn-ghost w-full justify-center">＋ New mail domain</a>
                    <form method="POST" action="{{ route('mail.install') }}"
                          onsubmit="return confirm('Re-run the repair flow? (installs or re-verifies everything)');">
                        @csrf
                        <button type="submit" class="btn-ghost w-full justify-center">↻ Repair</button>
                    </form>
                </div>

                <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
                    DNS: <span class="font-mono text-slate-400">mail.{{ config('panel.domain') }}</span>
                    must point at this server for certificates and MX. Ports 25/465/993/995/4190
                    must be open at your provider.
                </p>
            </div>
        </div>

        {{-- Domains --}}
        <div class="card mt-6 overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-800 px-6 py-4">
                <p class="eyebrow">mail domains</p>
                <a href="{{ route('mail.create') }}" class="btn-ghost">＋ New</a>
            </div>

            @if (count($domains) === 0)
                <div class="px-6 py-12 text-center">
                    <p class="eyebrow">no mail domains yet</p>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                        Add your first domain — mailboxes, MX and DKIM follow along.
                    </p>
                    <a href="{{ route('mail.create') }}" class="btn-primary mt-5">＋ New mail domain</a>
                </div>
            @else
                <table class="table">
                    <thead>
                    <tr>
                        <th>Domain</th>
                        <th>MX target</th>
                        <th class="w-40 text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($domains as $domain)
                        <tr>
                            <td>
                                <a href="{{ route('mail.show', $domain['id']) }}"
                                   class="mono-chip hover:border-amber-400 hover:text-amber-400">
                                    {{ $domain['name'] }}
                                </a>
                                @unless ($domain['enabled'])
                                    <span class="badge badge-slate ml-2">disabled</span>
                                @endunless
                            </td>
                            <td class="font-mono text-xs text-slate-500">mail.{{ $domain['name'] }}</td>
                            <td>
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('mail.show', $domain['id']) }}" class="btn-ghost">Open</a>
                                    <form method="POST" action="{{ route('mail.destroy', $domain['id']) }}"
                                          onsubmit="return confirm('Delete {{ $domain['name'] }} and ALL its mailboxes?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-ghost">Del</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($latest && $latest->status === 'failed')
            <div class="mt-6 rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
                <p class="text-xs text-rose-300">Last install attempt failed:</p>
                <p class="mt-1 font-mono text-[11px] text-rose-400/80">{{ $latest->message }}</p>
                <a href="{{ route('installs.show', $latest) }}" class="mt-2 inline-block font-mono text-[11px] text-amber-400 hover:underline">
                    view full log ↗
                </a>
            </div>
        @endif
    @endif
@endsection
