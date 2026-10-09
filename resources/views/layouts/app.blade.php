<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#0b1220] text-slate-300 antialiased">
<div class="flex min-h-screen">

    {{-- Sidebar --}}
    <aside id="sidebar"
           class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full border-r border-slate-800 bg-[#0a101c] transition-transform duration-200 lg:static lg:translate-x-0"
           aria-label="Main navigation">
        <div class="flex h-16 items-center gap-3 border-b border-slate-800 px-5">
            <span class="h-2.5 w-2.5 rounded-sm bg-amber-400 shadow-[0_0_10px_rgba(251,191,36,.7)]" aria-hidden="true"></span>
            <span class="font-mono text-sm font-semibold tracking-tight text-white">{{ config('app.name') }}</span>
        </div>

        <nav class="flex h-[calc(100%-4rem)] flex-col overflow-y-auto p-3">
            <p class="eyebrow px-3 pb-2 pt-3">overview</p>
            <a href="{{ route('dashboard') }}"
               class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}">
                <span aria-hidden="true">▤</span> Dashboard
            </a>

            <p class="eyebrow px-3 pb-2 pt-5">manage</p>
            <a href="{{ route('caddy.index') }}"
               class="nav-link {{ request()->routeIs('caddy.*') ? 'nav-link-active' : '' }}">
                <span aria-hidden="true">◈</span> Sites
            </a>
            <a href="{{ route('pma.index') }}"
               class="nav-link {{ request()->routeIs('pma.*') ? 'nav-link-active' : '' }}">
                <span aria-hidden="true">▦</span> phpMyAdmin
            </a>
            <a href="{{ route('dns.index') }}"
               class="nav-link {{ request()->routeIs('dns.*') ? 'nav-link-active' : '' }}">
                <span aria-hidden="true">@@</span> DNS
            </a>
            <a href="{{ route('mail.index') }}"
               class="nav-link {{ request()->routeIs('mail.*') ? 'nav-link-active' : '' }}">
                <span aria-hidden="true">✉</span> Email
            </a>
            <span class="nav-link nav-link-disabled"><span aria-hidden="true">⛃</span> Databases <em class="soon">soon</em></span>
            <span class="nav-link nav-link-disabled"><span aria-hidden="true">⇪</span> Projects <em class="soon">soon</em></span>

            <p class="eyebrow px-3 pb-2 pt-5">system</p>
            <span class="nav-link nav-link-disabled"><span aria-hidden="true">≡</span> Logs <em class="soon">soon</em></span>
            <span class="nav-link nav-link-disabled"><span aria-hidden="true">⚙</span> Settings <em class="soon">soon</em></span>

            <div class="mt-auto border-t border-slate-800 p-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="nav-link w-full text-left"><span aria-hidden="true">⏻</span> Sign out</button>
                </form>
            </div>
        </nav>
    </aside>
    <div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-black/60 lg:hidden" aria-hidden="true"></div>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-800 bg-[#0b1220]/90 px-4 backdrop-blur sm:px-6">
            <button id="sidebar-toggle" type="button"
                    class="rounded-md border border-slate-700 px-2.5 py-1.5 font-mono text-sm text-slate-400 hover:border-slate-500 hover:text-white lg:hidden"
                    aria-label="Toggle navigation" aria-expanded="false">☰</button>
            <p class="eyebrow truncate">{{ request()->host() }}</p>
            <div class="ml-auto flex items-center gap-3">
                <span class="hidden font-mono text-xs text-slate-500 sm:inline">{{ now()->format('D, M d · H:i') }}</span>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 p-4 sm:p-6 lg:p-8">
            @include('partials.flash')
            @yield('content')
        </main>

        <footer class="border-t border-slate-800 px-4 py-4 sm:px-6">
            <p class="font-mono text-[11px] uppercase tracking-widest text-slate-600">
                {{ config('app.name') }} · {{ php_uname('n') }} · php {{ PHP_VERSION }}
            </p>
        </footer>
    </div>
</div>

<script>
    (function () {
        var sidebar = document.getElementById('sidebar');
        var backdrop = document.getElementById('sidebar-backdrop');
        var toggle = document.getElementById('sidebar-toggle');

        function setOpen(open) {
            sidebar.classList.toggle('-translate-x-full', !open);
            backdrop.classList.toggle('hidden', !open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function () {
            setOpen(sidebar.classList.contains('-translate-x-full'));
        });
        backdrop.addEventListener('click', function () { setOpen(false); });
    })();
</script>
</body>
</html>
