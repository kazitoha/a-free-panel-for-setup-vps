@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">system</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Settings</h1>
        <p class="mt-1 max-w-xl text-sm text-slate-500">
            Panel-wide actions. Everything here is destructive — read it twice.
        </p>
    </div>

    @if ($active)
        <div class="card mb-6 flex items-center justify-between p-5">
            <div>
                <p class="eyebrow">reset in progress</p>
                <p class="mt-1 text-sm text-slate-300">{{ $active->label }}…</p>
                <p class="font-mono text-[11px] text-slate-600">started {{ $active->created_at->diffForHumans() }}</p>
            </div>
            <a href="{{ route('installs.show', $active) }}" class="btn-ghost">Watch ↗</a>
        </div>
    @endif

    <div class="card max-w-3xl border-rose-900/60 p-5">
        <div class="flex items-center justify-between">
            <p class="eyebrow">danger zone</p>
            <span class="badge badge-red">destructive</span>
        </div>

        <h2 class="mt-3 text-lg font-semibold text-rose-300">Reset the panel</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-400">
            Uninstalls every module — MySQL and all its databases, PowerDNS, Stalwart mail,
            phpMyAdmin and any extra PHP versions — deletes project files and Caddy sites,
            removes panel records, backups and cron jobs, and signs every user out.
        </p>
        <p class="mt-2 text-sm leading-relaxed text-slate-400">
            The panel itself, Caddy and the panel's own PHP stay online. When it finishes you are
            returned to <span class="font-mono text-slate-300">/first-run</span> to create a new
            administrator. <strong class="text-rose-300">This cannot be undone.</strong>
        </p>

        @if ($active)
            <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 font-mono text-[11px] text-slate-500">
                A reset is already running. Wait for it to finish before starting another.
            </p>
        @else
            <form method="POST" action="{{ route('settings.reset') }}" class="mt-5"
                  onsubmit="return confirm('Reset the panel? Every module and all panel data will be permanently removed.');">
                @csrf
                <label for="reset-confirm" class="label">
                    Type <span class="text-slate-300">{{ $domain }}</span> to confirm
                </label>
                <input id="reset-confirm" type="text" class="input" autocomplete="off"
                       autocapitalize="none" spellcheck="false" placeholder="{{ $domain }}">

                <button id="reset-submit" type="submit" class="btn-danger mt-4" disabled>
                    Reset the panel
                </button>
            </form>

            <script>
                (function () {
                    var input = document.getElementById('reset-confirm');
                    var button = document.getElementById('reset-submit');

                    input.addEventListener('input', function () {
                        button.disabled = input.value.trim() !== {{ json_encode($domain) }};
                    });
                })();
            </script>
        @endif

        @if ($latest && $latest->status === 'failed')
            <div class="mt-4 rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
                <p class="text-xs text-rose-300">Last reset failed:</p>
                <p class="mt-1 font-mono text-[11px] text-rose-400/80">{{ $latest->message }}</p>
                <a href="{{ route('installs.show', $latest) }}" class="mt-2 inline-block font-mono text-[11px] text-amber-400 hover:underline">
                    view full log ↗
                </a>
            </div>
        @endif
    </div>
@endsection
