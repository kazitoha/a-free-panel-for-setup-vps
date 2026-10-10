@extends('layouts.app')

@section('title', 'Setup')

@section('content')
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">setup / {{ $setup['domain'] }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Setup</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">
                What this panel is, the DNS records your registrar needs, the database
                credentials, and what is still left to install.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="mono-chip">{{ $setup['done'] }} / {{ $setup['total'] }} done</span>
            @if ($setup['required_pending'] > 0)
                <span class="badge badge-amber">{{ $setup['required_pending'] }} required left</span>
            @else
                <span class="badge badge-green">ready</span>
            @endif
        </div>
    </header>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Panel identity --}}
        <div class="card p-5">
            <p class="eyebrow">panel</p>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Main domain</dt>
                    <dd class="font-mono text-slate-300">{{ $setup['domain'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">URL</dt>
                    <dd class="font-mono text-slate-300">
                        <a href="{{ $setup['url'] }}" target="_blank" rel="noopener"
                           class="text-amber-400 hover:underline">{{ $setup['url'] }} ↗</a>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">PHP running</dt>
                    <dd class="font-mono text-slate-300">{{ $setup['php']['running'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">PHP for modules</dt>
                    <dd class="font-mono text-slate-300">{{ $setup['php']['installed'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Administrator</dt>
                    <dd class="font-mono text-slate-300">{{ $setup['admin'] ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- DNS records to register --}}
        <div class="card p-5 lg:col-span-2">
            <div class="flex items-center justify-between">
                <p class="eyebrow">nameservers</p>
                @if ($setup['dns']['server_ip'] !== '')
                    <span class="mono-chip" title="Server IP">{{ $setup['dns']['server_ip'] }}</span>
                @else
                    <span class="badge badge-amber">no server IP</span>
                @endif
            </div>

            <p class="mt-3 text-sm text-slate-500">
                Register these with your registrar for
                <span class="font-mono text-slate-400">{{ $setup['dns']['ns_domain'] }}</span>,
                then point the panel domain at this server so Caddy can issue the certificate.
            </p>

            @if ($setup['dns']['records'] !== [])
                <table class="table mt-4">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Value</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($setup['dns']['records'] as $record)
                        <tr>
                            <td class="font-mono text-sm text-slate-300">{{ $record['name'] }}</td>
                            <td class="text-sm text-slate-400">{{ $record['type'] }}</td>
                            <td class="font-mono text-sm text-slate-300">{{ $record['content'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs text-slate-500">
                    No nameserver domain configured yet.
                </p>
            @endif
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- Database credentials --}}
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <p class="eyebrow">database</p>
                <span class="badge badge-slate">{{ $setup['database']['engine'] }}</span>
            </div>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Root user</dt>
                    <dd class="font-mono text-slate-300">{{ $setup['database']['user'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Root password</dt>
                    <dd class="flex items-center gap-2 font-mono text-slate-300">
                        <span id="db-secret"
                              data-value="{{ $setup['database']['password'] }}"
                              data-shown="0">
                            {{ $setup['database']['password'] !== '' ? '••••••••••' : '(not set)' }}
                        </span>
                        @if ($setup['database']['password'] !== '')
                            <button type="button" onclick="toggleSecret()"
                                    class="font-mono text-[11px] text-amber-400 hover:underline">show</button>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">phpMyAdmin login</dt>
                    <dd class="font-mono text-slate-300">root / same password</dd>
                </div>
            </dl>

            <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
                Site databases get their own user and password from the Databases page —
                the root account above is only for administration.
            </p>
        </div>

        {{-- Checklist --}}
        <div class="card p-5 lg:col-span-2">
            <div class="flex items-center justify-between">
                <p class="eyebrow">checklist</p>
                <a href="{{ route('dashboard') }}" class="font-mono text-[11px] text-amber-400 hover:underline">
                    back to dashboard
                </a>
            </div>

            <ul class="mt-4 space-y-3">
                @foreach ($setup['items'] as $item)
                    <li class="flex items-start justify-between gap-4 rounded-lg border border-slate-800 bg-[#0a101c] p-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-{{ $item['status'] === 'ok' ? 'green' : ($item['status'] === 'fail' ? 'red' : 'amber') }}">
                                    {{ $item['status'] === 'ok' ? 'done' : ($item['status'] === 'fail' ? 'failed' : 'pending') }}
                                </span>
                                <p class="text-sm font-medium text-slate-200">{{ $item['label'] }}</p>
                                @if ($item['optional'])
                                    <span class="badge badge-slate">optional</span>
                                @endif
                            </div>
                            <p class="mt-1.5 text-sm text-slate-500">{{ $item['detail'] }}</p>
                        </div>

                        @if ($item['action'] !== null)
                            @if ($item['action']['method'] === 'POST')
                                <form method="POST" action="{{ $item['action']['url'] }}"
                                      onsubmit="return confirm('{{ $item['action']['label'] }}?');">
                                    @csrf
                                    <button type="submit" class="btn-ghost shrink-0">
                                        {{ $item['action']['label'] }}
                                    </button>
                                </form>
                            @elseif ($item['action']['method'] === 'external')
                                <a href="{{ $item['action']['url'] }}" target="_blank" rel="noopener"
                                   class="btn-ghost shrink-0">{{ $item['action']['label'] }} ↗</a>
                            @else
                                <a href="{{ $item['action']['url'] }}" class="btn-ghost shrink-0">
                                    {{ $item['action']['label'] }}
                                </a>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        function toggleSecret() {
            const el = document.getElementById('db-secret');
            const shown = el.dataset.shown === '1';
            el.dataset.shown = shown ? '0' : '1';
            el.textContent = shown ? '••••••••••' : el.dataset.value;
        }
    </script>
@endsection
