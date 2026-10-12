<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Finish setup') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-[#0b1220] p-4 text-slate-300 antialiased">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center gap-3">
            <span class="h-2.5 w-2.5 rounded-sm bg-amber-400 shadow-[0_0_10px_rgba(251,191,36,.7)]" aria-hidden="true"></span>
            <span class="font-mono text-sm font-semibold tracking-tight text-white">{{ config('app.name') }}</span>
        </div>

        <div class="card p-6">
            <p class="eyebrow">first run</p>
            <h1 class="mt-1 mb-2 text-xl font-semibold tracking-tight text-white">Finish installing the panel</h1>
            <p class="mb-6 text-sm leading-relaxed text-slate-400">
                Everything else — packages, PHP, Caddy, PowerDNS and the panel code — is already installed
                and was skipped. Set the credentials below once, then sign in.
            </p>

            @include('partials.flash')

            <form method="POST" action="{{ route('firstrun.store') }}" class="space-y-4">
                @csrf

                @if ($needsMysql)
                    <div>
                        <label for="mysql_password" class="label">MySQL root password</label>
                        <input id="mysql_password" name="mysql_password" type="password" required
                               autocomplete="off" autofocus
                               class="input @error('mysql_password') border-rose-500/60 @enderror"
                               placeholder="••••••••">
                        @error('mysql_password')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                        <p class="mt-2 text-xs leading-relaxed text-slate-500">
                            The password MySQL already has on this server. On a fresh server, type the password
                            you want it to use — the installer stores it in <span class="font-mono">.env</span>
                            and <span class="font-mono">/root/.panel-secrets</span>.
                        </p>
                    </div>
                @endif

                @if ($needsAdmin)
                    <div>
                        <label for="email" class="label">Administrator email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required
                               class="input @error('email') border-rose-500/60 @enderror"
                               placeholder="you@example.com">
                        @error('email')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="label">Administrator password</label>
                        <input id="password" name="password" type="password" required
                               class="input @error('password') border-rose-500/60 @enderror"
                               placeholder="at least 8 characters">
                        @error('password')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="label">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required
                               class="input @error('password_confirmation') border-rose-500/60 @enderror"
                               placeholder="••••••••">
                        @error('password_confirmation')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                @else
                    <p class="rounded-lg border border-slate-700/60 bg-slate-900/40 px-4 py-3 text-sm text-slate-400">
                        The administrator account already exists — you will sign in with it below.
                    </p>
                @endif

                <button type="submit" class="btn-primary w-full justify-center">Complete setup</button>
            </form>
        </div>

        <p class="mt-4 font-mono text-[11px] uppercase tracking-widest text-slate-600">
            https · first run · {{ request()->host() }}
        </p>
    </div>
</body>
</html>
