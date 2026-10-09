@extends('layouts.app')

@section('title', 'phpMyAdmin')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">module</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">phpMyAdmin</h1>
        <p class="mt-1 max-w-xl text-sm text-slate-500">
            Browser interface for MySQL — installed to
            <span class="font-mono text-slate-400">{{ $dir }}</span> and served over HTTPS.
        </p>
    </div>

    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
        {{-- Status --}}
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <p class="eyebrow">status</p>
                @if ($installed)
                    <span class="badge badge-green">installed</span>
                @else
                    <span class="badge badge-slate">not installed</span>
                @endif
            </div>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">URL</dt>
                    <dd class="font-mono text-slate-300">
                        @if ($installed)
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="text-amber-400 hover:underline">
                                {{ $url }} ↗
                            </a>
                        @else
                            {{ $url }}
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Path</dt>
                    <dd class="font-mono text-slate-300">{{ $dir }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Panel PHP</dt>
                    <dd class="font-mono text-slate-300">{{ $phpVersion }}</dd>
                </div>
            </dl>

            <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
                DNS: <span class="font-mono text-slate-400">pma.{{ config('panel.domain') }}</span>
                must point at this server. Caddy issues the certificate automatically
                on first request.
            </p>
        </div>

        {{-- Actions --}}
        <div class="card p-5">
            <p class="eyebrow">actions</p>

            <div class="mt-4 space-y-3">
                @if ($active)
                    <div class="flex items-center justify-between rounded-md border border-slate-800 bg-[#0a101c] p-3">
                        <div>
                            <p class="text-sm text-slate-300">{{ $active->label }}…</p>
                            <p class="font-mono text-[11px] text-slate-600">started {{ $active->created_at->diffForHumans() }}</p>
                        </div>
                        <a href="{{ route('installs.show', $active) }}" class="btn-ghost">Watch ↗</a>
                    </div>
                @elseif (! $installed)
                    <form method="POST" action="{{ route('pma.install') }}"
                          onsubmit="return confirm('Download and install phpMyAdmin?');">
                        @csrf
                        <button type="submit" class="btn-primary w-full justify-center">⬇ Install phpMyAdmin</button>
                    </form>
                @else
                    <a href="{{ $url }}" target="_blank" rel="noopener"
                       class="btn-primary w-full justify-center">Open phpMyAdmin ↗</a>

                    <form method="POST" action="{{ route('pma.uninstall') }}"
                          onsubmit="return confirm('Remove phpMyAdmin and its site?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-ghost w-full justify-center">Uninstall</button>
                    </form>
                @endif
            </div>

            @if (! $installed && $latest && $latest->status === 'failed')
                <div class="mt-4 rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
                    <p class="text-xs text-rose-300">Last attempt failed:</p>
                    <p class="mt-1 font-mono text-[11px] text-rose-400/80">{{ $latest->message }}</p>
                    <a href="{{ route('installs.show', $latest) }}" class="mt-2 inline-block font-mono text-[11px] text-amber-400 hover:underline">
                        view full log ↗
                    </a>
                </div>
            @endif
        </div>
    </div>
@endsection
