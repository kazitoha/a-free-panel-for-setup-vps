@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $activeCount = count(array_filter($system['services'], fn ($s) => $s['state'] === 'active'));
        $serviceCount = count($system['services']);
        $uptimeSeconds = $system['uptime_seconds'];
        $uptime = [
            'days' => intdiv($uptimeSeconds, 86400),
            'hours' => intdiv($uptimeSeconds % 86400, 3600),
            'minutes' => intdiv($uptimeSeconds % 3600, 60),
        ];

        $barClass = fn (int $percent): string => match (true) {
            $percent >= 90 => 'meter-bar-rose',
            $percent >= 70 => 'meter-bar-amber',
            default => 'meter-bar-green',
        };
    @endphp

    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">server / {{ $system['hostname'] }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Dashboard</h1>
        </div>
        <div class="flex items-center gap-3">
            @if ($system['public_ip'])
                <span class="mono-chip" title="Public IPv4">{{ $system['public_ip'] }}</span>
            @endif
            <a href="{{ route('dashboard') }}" class="btn-ghost">Refresh</a>
        </div>
    </header>

    {{-- Service status bus --}}
    <section class="card mb-6 p-5" aria-label="Service status">
        <div class="mb-4 flex items-center justify-between gap-4">
            <p class="eyebrow">services</p>
            <p class="font-mono text-xs text-slate-500">
                <span class="{{ $activeCount === $serviceCount ? 'text-emerald-400' : 'text-amber-400' }}">{{ $activeCount }}</span>
                / {{ $serviceCount }} running
            </p>
        </div>

        @if ($serviceCount === 0)
            <p class="text-sm text-slate-500">No services detected yet. Install modules from the panel to see them here.</p>
        @else
            <ul class="flex flex-wrap gap-3">
                @foreach ($system['services'] as $service)
                    <li class="flex items-center gap-3 rounded-lg border border-slate-800 bg-[#0a101c] px-3.5 py-2.5">
                        <span class="led led--{{ $service['state'] }}" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm font-medium text-slate-200">{{ $service['name'] }}</p>
                            <p class="state-label state-label--{{ $service['state'] }}">{{ $service['state'] }}</p>
                        </div>
                        <form method="POST" action="{{ route('services.restart') }}"
                              onsubmit="return confirm('Restart {{ $service['unit'] }}?')">
                            @csrf
                            <input type="hidden" name="unit" value="{{ $service['unit'] }}">
                            <button type="submit" class="icon-btn" title="Restart {{ $service['name'] }}"
                                    aria-label="Restart {{ $service['name'] }}">↻</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Resource meters --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <article class="card p-5">
            <div class="flex items-baseline justify-between">
                <p class="eyebrow">load</p>
                <p class="font-mono text-[11px] text-slate-600">1m/5m/15m</p>
            </div>
            <p class="mt-3 font-mono text-3xl font-semibold text-white">
                {{ number_format($system['load']['load1'], 2) }}
                <span class="text-sm font-normal text-slate-500">/ {{ $system['load']['cores'] }} cores</span>
            </p>
            <div class="meter mt-4">
                <div class="meter-bar {{ $barClass($system['load']['percent']) }}" style="width: {{ $system['load']['percent'] }}%"></div>
            </div>
            <p class="mt-2 font-mono text-[11px] text-slate-600">
                {{ number_format($system['load']['load5'], 2) }} · {{ number_format($system['load']['load15'], 2) }} · {{ $system['load']['percent'] }}%
            </p>
        </article>

        <article class="card p-5">
            <div class="flex items-baseline justify-between">
                <p class="eyebrow">memory</p>
                <p class="font-mono text-[11px] text-slate-600">ram</p>
            </div>
            <p class="mt-3 font-mono text-3xl font-semibold text-white">
                {{ number_format($system['memory']['used_mb']) }}
                <span class="text-sm font-normal text-slate-500">/ {{ number_format($system['memory']['total_mb']) }} MB</span>
            </p>
            <div class="meter mt-4">
                <div class="meter-bar {{ $barClass($system['memory']['percent']) }}" style="width: {{ $system['memory']['percent'] }}%"></div>
            </div>
            <p class="mt-2 font-mono text-[11px] text-slate-600">{{ $system['memory']['percent'] }}% in use</p>
        </article>

        <article class="card p-5">
            <div class="flex items-baseline justify-between">
                <p class="eyebrow">disk</p>
                <p class="font-mono text-[11px] text-slate-600">/ root</p>
            </div>
            <p class="mt-3 font-mono text-3xl font-semibold text-white">
                {{ number_format($system['disk']['used_gb'], 1) }}
                <span class="text-sm font-normal text-slate-500">/ {{ number_format($system['disk']['total_gb'], 1) }} GB</span>
            </p>
            <div class="meter mt-4">
                <div class="meter-bar {{ $barClass($system['disk']['percent']) }}" style="width: {{ $system['disk']['percent'] }}%"></div>
            </div>
            <p class="mt-2 font-mono text-[11px] text-slate-600">{{ $system['disk']['percent'] }}% in use</p>
        </article>
    </div>

    {{-- System details --}}
    <section class="card p-5" aria-label="System details">
        <p class="eyebrow mb-4">system</p>

        <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
            <div class="flex items-baseline justify-between gap-4 border-b border-slate-800/70 pb-3">
                <dt class="text-sm text-slate-500">Operating system</dt>
                <dd class="truncate font-mono text-sm text-slate-300">{{ $system['os'] }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 border-b border-slate-800/70 pb-3">
                <dt class="text-sm text-slate-500">Uptime</dt>
                <dd class="font-mono text-sm text-slate-300">
                    {{ $uptime['days'] }}d {{ $uptime['hours'] }}h {{ $uptime['minutes'] }}m
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 border-b border-slate-800/70 pb-3">
                <dt class="text-sm text-slate-500">PHP versions</dt>
                <dd class="flex flex-wrap justify-end gap-1.5">
                    @forelse ($system['php_versions'] as $version)
                        <span class="mono-chip">php {{ $version }}</span>
                    @empty
                        <span class="font-mono text-sm text-slate-600">none</span>
                    @endforelse
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 border-b border-slate-800/70 pb-3">
                <dt class="text-sm text-slate-500">Listening ports</dt>
                <dd class="flex flex-wrap justify-end gap-1.5">
                    @forelse ($system['listening_ports'] as $port)
                        <span class="mono-chip">{{ $port }}</span>
                    @empty
                        <span class="font-mono text-sm text-slate-600">none detected</span>
                    @endforelse
                </dd>
            </div>
        </dl>
    </section>
@endsection
