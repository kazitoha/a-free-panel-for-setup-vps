@extends('layouts.app')

@section('title', $install->label)

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">install · #{{ $install->id }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">{{ $install->label }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span id="install-badge"
                      class="badge {{ $install->status === 'success' ? 'badge-green' : ($install->status === 'failed' ? 'badge-red' : 'badge-amber') }}">
                    {{ $install->displayStatus() }}
                </span>
                <span class="font-mono text-[11px] text-slate-600">
                    {{ $install->created_at->format('Y-m-d H:i:s') }}
                </span>
            </div>
        </div>

        <div class="flex gap-2">
            @if ($install->type === 'update')
                <a href="{{ route('dashboard') }}" class="btn-ghost">Back to Dashboard</a>
            @elseif ($install->type === 'pma')
                <a href="{{ route('pma.index') }}" class="btn-ghost">Back to phpMyAdmin</a>
            @elseif ($install->type === 'php')
                <a href="{{ route('php.index') }}" class="btn-ghost">Back to PHP versions</a>
            @else
                <a href="{{ route('caddy.index') }}" class="btn-ghost">Back to Sites</a>
            @endif
        </div>
    </div>

    @if ($install->message)
        <div class="mb-4 rounded-md border border-rose-900/60 bg-rose-950/30 p-3 text-sm text-rose-300">
            {{ $install->message }}
        </div>
    @endif

    <div class="card p-2">
        <pre id="install-log" class="terminal" aria-live="polite">Waiting for output…</pre>
    </div>

    <script>
        (function () {
            var log = document.getElementById('install-log');
            var badge = document.getElementById('install-badge');
            var finished = {{ $install->isFinished() ? 'true' : 'false' }};
            var timer = null;
            var lastContent = '';

            function render(data) {
                if (data.content && data.content !== lastContent) {
                    lastContent = data.content;
                    log.textContent = data.content;
                    log.scrollTop = log.scrollHeight;
                }

                badge.textContent = data.status;
                badge.className = 'badge ' + (
                    data.status === 'success' ? 'badge-green' :
                    data.status === 'failed' || data.status === 'stalled' ? 'badge-red' :
                    'badge-amber'
                );

                if (data.finished) {
                    finished = true;
                    clearInterval(timer);

                    if (data.message && data.status !== 'success') {
                        log.textContent += (lastContent ? '\n' : '') + '⚠ ' + data.message;
                    }
                }
            }

            function poll() {
                fetch({{ json_encode(route('installs.log', $install)) }}, {
                    headers: { 'Accept': 'application/json' }
                })
                    .then(function (response) { return response.json(); })
                    .then(render)
                    .catch(function () { /* transient — retry */ });
            }

            poll();

            if (!finished) {
                timer = setInterval(poll, 1500);
            }
        })();
    </script>
@endsection
