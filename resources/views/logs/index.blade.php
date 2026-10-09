@extends('layouts.app')

@section('title', 'Logs')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">system · logs</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Logs</h1>
        <p class="mt-1 max-w-xl text-sm text-slate-500">
            Journal entries for panel services, the application log and the live Caddyfile.
        </p>
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['journal' => 'Journal', 'app' => 'App logs', 'caddyfile' => 'Caddyfile'] as $key => $label)
            <a href="{{ route('logs.index', ['source' => $key]) }}"
               class="btn-ghost {{ $source === $key ? 'border-amber-400/60 text-amber-400' : '' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-md border border-emerald-900/60 bg-emerald-950/30 p-3">
            <p class="text-xs text-emerald-300">{{ session('success') }}</p>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
            <p class="text-xs text-rose-300">{{ session('error') }}</p>
        </div>
    @endif

    @if ($source === 'journal')
        <form method="GET" action="{{ route('logs.index') }}" class="card mb-4 flex flex-wrap items-end gap-4 p-4">
            <input type="hidden" name="source" value="journal">
            <div>
                <label class="label" for="unit">Service</label>
                <select class="input" id="unit" name="unit">
                    @foreach ($units as $candidate)
                        <option value="{{ $candidate['unit'] }}" @selected($unit === $candidate['unit'])>
                            {{ $candidate['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="lines">Lines</label>
                <input class="input w-24 font-mono" id="lines" name="lines" type="number"
                       min="50" max="1000" value="{{ $lines }}">
            </div>
            <button type="submit" class="btn-primary">Load</button>
        </form>

        @if ($error)
            <div class="rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
                <p class="text-xs text-rose-300">{{ $error }}</p>
            </div>
        @elseif ($content)
            <pre class="terminal max-h-[70vh] overflow-auto p-4 text-[11px] leading-relaxed">{{ $content }}</pre>
        @else
            <div class="card p-6 text-center">
                <p class="eyebrow">no entries</p>
                <p class="mt-2 text-sm text-slate-500">Nothing in the journal for this service yet.</p>
            </div>
        @endif

    @elseif ($source === 'app')
        @if ($files === [])
            <div class="card p-6 text-center">
                <p class="eyebrow">no log files</p>
                <p class="mt-2 text-sm text-slate-500">The application has not written any logs yet.</p>
            </div>
        @else
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach ($files as $entry)
                    <a href="{{ route('logs.index', ['source' => 'app', 'file' => $entry['name']]) }}"
                       class="btn-ghost font-mono text-[11px] {{ $file === $entry['name'] ? 'border-amber-400/60 text-amber-400' : '' }}">
                        {{ $entry['name'] }}
                        <span class="text-slate-600">· {{ number_format($entry['size'] / 1024, 1) }} KB</span>
                    </a>
                @endforeach
            </div>

            <pre class="terminal max-h-[70vh] overflow-auto p-4 text-[11px] leading-relaxed">{{ $content ?? '(empty)' }}</pre>
        @endif

    @else
        <form method="POST" action="{{ route('logs.caddyfile') }}" class="card p-4">
            @csrf
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <p class="eyebrow">{{ config('panel.caddyfile') }}</p>
                <span class="text-[11px] text-slate-600">
                    validated before saving · rolled back automatically when Caddy rejects it
                </span>
            </div>

            @if ($error)
                <div class="mb-3 rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
                    <p class="text-xs text-rose-300">{{ $error }}</p>
                </div>
            @endif

            <textarea class="input h-[60vh] w-full resize-y font-mono text-[11px] leading-relaxed @error('content') border-rose-400 @enderror"
                      name="content" spellcheck="false">{{ old('content', $content ?? '') }}</textarea>
            @error('content')<p class="field-error">{{ $message }}</p>@enderror

            <div class="mt-4 flex gap-3">
                <button type="submit" class="btn-primary">Save Caddyfile</button>
                <button type="reset" class="btn-ghost">Reset</button>
            </div>
        </form>
    @endif
@endsection
