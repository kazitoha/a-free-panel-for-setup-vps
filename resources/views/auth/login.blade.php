<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-[#0b1220] p-4 text-slate-300 antialiased">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center gap-3">
            <span class="h-2.5 w-2.5 rounded-sm bg-amber-400 shadow-[0_0_10px_rgba(251,191,36,.7)]" aria-hidden="true"></span>
            <span class="font-mono text-sm font-semibold tracking-tight text-white">{{ config('app.name') }}</span>
        </div>

        <div class="card p-6">
            <p class="eyebrow">secure sign-in</p>
            <h1 class="mt-1 mb-6 text-xl font-semibold tracking-tight text-white">Sign in to the panel</h1>

            @include('partials.flash')

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           class="input @error('email', 'login') border-rose-500/60 @enderror"
                           placeholder="you@example.com">
                    @error('email', 'login')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="label">Password</label>
                    <input id="password" name="password" type="password" required
                           class="input @error('password') border-rose-500/60 @enderror"
                           placeholder="••••••••">
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-400">
                    <input type="checkbox" name="remember" value="1" class="rounded border-slate-600 bg-slate-800 text-amber-400 focus:ring-amber-400/40">
                    Keep me signed in
                </label>

                <button type="submit" class="btn-primary w-full justify-center">Sign in</button>
            </form>

            @if ($googleEnabled)
                <div class="my-4 flex items-center gap-3" aria-hidden="true">
                    <span class="h-px flex-1 bg-slate-800"></span>
                    <span class="eyebrow">or</span>
                    <span class="h-px flex-1 bg-slate-800"></span>
                </div>

                <a href="{{ route('auth.google') }}" class="btn-ghost w-full justify-center">
                    Sign in with Google
                </a>
            @endif
        </div>

        <p class="mt-4 font-mono text-[11px] uppercase tracking-widest text-slate-600">
            https · single admin · {{ request()->host() }}
        </p>
    </div>
</body>
</html>
