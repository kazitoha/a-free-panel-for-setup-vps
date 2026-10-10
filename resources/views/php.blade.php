@extends('layouts.app')

@section('title', 'PHP versions')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">module</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">PHP versions</h1>
        <p class="mt-1 max-w-xl text-sm text-slate-500">
            Every PHP version installed on this server. Sites and Projects can pick any of
            them, and adding another takes a couple of minutes in the background.
        </p>
    </div>

    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
        {{-- Installed --}}
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <p class="eyebrow">installed</p>
                <span class="mono-chip">{{ count($installed) }}</span>
            </div>

            @if ($installed !== [])
                <ul class="mt-4 space-y-3">
                    @foreach ($installed as $version)
                        <li class="flex items-center justify-between gap-3 rounded-lg border border-slate-800 bg-[#0a101c] p-3">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-sm text-slate-200">{{ $version }}</span>
                                @if ($version === $panelPhp)
                                    <span class="badge badge-amber">panel</span>
                                @endif
                            </div>

                            @if ($version !== $panelPhp)
                                <form method="POST" action="{{ route('php.remove') }}"
                                      onsubmit="return confirm('Remove PHP {{ $version }}? Sites still using it will stop working.');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="version" value="{{ $version }}">
                                    <button type="submit" class="font-mono text-[11px] text-rose-400 hover:underline">
                                        remove
                                    </button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs text-slate-500">
                    No PHP version found under <span class="font-mono">{{ config('panel.php_dir') }}</span>.
                </p>
            @endif
        </div>

        {{-- Installable --}}
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <p class="eyebrow">add another</p>
                <span class="mono-chip">ondrej/php</span>
            </div>

            @if ($active)
                <div class="mt-4 flex items-center justify-between rounded-md border border-slate-800 bg-[#0a101c] p-3">
                    <div>
                        <p class="text-sm text-slate-300">{{ $active->label }}…</p>
                        <p class="font-mono text-[11px] text-slate-600">started {{ $active->created_at->diffForHumans() }}</p>
                    </div>
                    <a href="{{ route('installs.show', $active) }}" class="btn-ghost">Watch ↗</a>
                </div>
            @elseif ($installable === [])
                <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs text-slate-500">
                    Nothing to add — every version the repositories offer is already installed.
                </p>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($installable as $version)
                        <li class="flex items-center justify-between gap-3 rounded-lg border border-slate-800 bg-[#0a101c] p-3">
                            <span class="font-mono text-sm text-slate-200">{{ $version }}</span>

                            <form method="POST" action="{{ route('php.install') }}"
                                  onsubmit="return confirm('Install PHP {{ $version }} (FPM, CLI and extensions)?');">
                                @csrf
                                <input type="hidden" name="version" value="{{ $version }}">
                                <button type="submit" class="btn-ghost">⬇ Install</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($latestFailed)
                <div class="mt-4 rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
                    <p class="text-xs text-rose-300">Last attempt failed:</p>
                    <p class="mt-1 font-mono text-[11px] text-rose-400/80">{{ $latestFailed->message }}</p>
                    <a href="{{ route('installs.show', $latestFailed) }}"
                       class="mt-2 inline-block font-mono text-[11px] text-amber-400 hover:underline">
                        view full log ↗
                    </a>
                </div>
            @endif
        </div>
    </div>

    <p class="mt-6 max-w-4xl rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
        The panel itself runs on
        <span class="font-mono text-slate-400">{{ $panelPhp }}</span> and is never offered for
        removal. A version appears in the Sites and Projects pickers as soon as its install finishes.
    </p>
@endsection
