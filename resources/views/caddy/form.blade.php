@extends('layouts.app')

@section('title', $editing ? 'Edit site' : 'New site')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">caddy · {{ $editing ? 'edit' : 'create' }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">
            {{ $editing ? "Edit {$editing}" : 'New site' }}
        </h1>
    </div>

    <form method="POST"
          action="{{ $editing ? route('caddy.update', $editing) : route('caddy.store') }}"
          class="grid max-w-3xl gap-6">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        {{-- Domain --------------------------------------------------------- --}}
        <div class="card p-5">
            <label class="label" for="domain">Domain</label>
            <input class="input @error('domain') border-rose-400 @enderror"
                   id="domain" name="domain" type="text" required
                   placeholder="example.com" value="{{ old('domain', $spec['domain']) }}">
            @error('domain')<p class="field-error">{{ $message }}</p>@enderror
            <label class="mt-3 flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" name="wildcard" value="1"
                       class="h-4 w-4 accent-amber-400"
                       @checked(old('wildcard', $spec['wildcard']))>
                Also serve <span class="mono-chip">*.{{ old('domain', $spec['domain']) ?: 'example.com' }}</span>
                (wildcard — needs DNS wildcard record)
            </label>
        </div>

        {{-- Type ----------------------------------------------------------- --}}
        <div class="card p-5">
            <p class="label">Site type</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="type-card">
                    <input type="radio" name="type" value="php"
                           @checked(old('type', $spec['type']) === 'php')>
                    <strong>PHP</strong>
                    <span>Serve a PHP project through php-fpm</span>
                </label>
                <label class="type-card">
                    <input type="radio" name="type" value="static"
                           @checked(old('type', $spec['type']) === 'static')>
                    <strong>Static</strong>
                    <span>Plain files — HTML, assets, builds</span>
                </label>
                <label class="type-card">
                    <input type="radio" name="type" value="proxy"
                           @checked(old('type', $spec['type']) === 'proxy')>
                    <strong>Proxy</strong>
                    <span>Forward to a local app (Node, Go…)</span>
                </label>
            </div>
            @error('type')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        {{-- PHP options ---------------------------------------------------- --}}
        <div class="card p-5" data-when="php">
            <label class="label" for="php_version">PHP version (php-fpm)</label>
            <select class="input" id="php_version" name="php_version">
                @foreach ($phpVersions as $version)
                    <option value="{{ $version }}"
                        @selected(old('php_version', $spec['php_version']) === $version)>
                        PHP {{ $version }}
                    </option>
                @endforeach
            </select>
            @error('php_version')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        {{-- Document root --------------------------------------------------- --}}
        <div class="card p-5" data-when="php static">
            <label class="label" for="docroot">Document root</label>
            <input class="input @error('docroot') border-rose-400 @enderror"
                   id="docroot" name="docroot" type="text" required
                   placeholder="/var/www/example.com/public"
                   value="{{ old('docroot', $spec['docroot']) }}">
            @error('docroot')<p class="field-error">{{ $message }}</p>@enderror
            <p class="mt-2 font-mono text-[11px] text-slate-600">must live under /var/www</p>
        </div>

        {{-- Proxy target ---------------------------------------------------- --}}
        <div class="card p-5" data-when="proxy">
            <label class="label" for="target">Upstream target</label>
            <input class="input @error('target') border-rose-400 @enderror"
                   id="target" name="target" type="text"
                   placeholder="127.0.0.1:3000"
                   value="{{ old('target', $spec['target']) }}">
            @error('target')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary">
                {{ $editing ? 'Save site' : 'Create site' }}
            </button>
            <a href="{{ route('caddy.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>

    <script>
        (function () {
            var cards = document.querySelectorAll('.type-card input');
            var sections = document.querySelectorAll('[data-when]');
            var domainInput = document.getElementById('domain');
            var docrootInput = document.getElementById('docroot');
            var docrootTouched = docrootInput.value !== '';

            docrootInput.addEventListener('input', function () { docrootTouched = true; });

            function refresh() {
                var type = (document.querySelector('.type-card input:checked') || {}).value || 'php';

                sections.forEach(function (section) {
                    var match = section.getAttribute('data-when').split(' ').indexOf(type) !== -1;
                    section.style.display = match ? '' : 'none';

                    section.querySelectorAll('input, select').forEach(function (field) {
                        field.disabled = !match;
                    });
                });

                // Suggest a document root from the domain until the user edits it.
                if (!docrootTouched && type !== 'proxy') {
                    var domain = domainInput.value.trim();
                    docrootInput.value = domain ? '/var/www/' + domain + '/public' : '';
                }
            }

            cards.forEach(function (card) { card.addEventListener('change', refresh); });
            domainInput.addEventListener('input', refresh);
            refresh();
        })();
    </script>
@endsection
