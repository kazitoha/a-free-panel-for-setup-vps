@extends('layouts.app')

@section('title', 'Databases')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">mysql · databases</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Databases</h1>
            <p class="mt-1 max-w-xl text-sm text-slate-500">
                MySQL databases and users created through the panel. Passwords are stored
                encrypted and revealed only when you ask for them.
            </p>
        </div>
    </div>

    @if ($needsInstall)
        <div class="card mb-6 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="eyebrow">mysql not reachable</p>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-400">
                        The panel cannot talk to MySQL on this server, so databases cannot be created yet.
                        Installing sets up the server, sets a root password it generates itself and stores
                        it in <span class="font-mono">.env</span> and
                        <span class="font-mono">/root/.panel-secrets</span>.
                    </p>
                </div>
                @if ($active)
                    <a href="{{ route('installs.show', $active) }}" class="btn-primary">Watch install ↗</a>
                @else
                    <form method="POST" action="{{ route('databases.install') }}"
                          onsubmit="return confirm('Install MySQL now?');">
                        @csrf
                        <button type="submit" class="btn-primary">⬇ Install MySQL</button>
                    </form>
                @endif
            </div>

            @if ($latest && $latest->status === 'failed')
                <div class="mt-4 rounded-md border border-rose-900/60 bg-rose-950/30 p-3">
                    <p class="text-xs text-rose-300">Last attempt failed:</p>
                    <p class="mt-1 font-mono text-[11px] text-rose-400/80">{{ $latest->message }}</p>
                </div>
            @endif
        </div>
    @elseif ($listError)
        <div class="mb-6 rounded-md border border-amber-900/60 bg-amber-950/30 p-3">
            <p class="text-xs text-amber-300">{{ $listError }}</p>
        </div>
    @endif

    {{-- Create --}}
    <form method="POST" action="{{ route('databases.store') }}" class="card p-5" @if ($needsInstall) hidden @endif>
        @csrf
        <p class="eyebrow">new database</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label" for="name">Database name</label>
                <input class="input font-mono @error('name') border-rose-400 @enderror"
                       id="name" name="name" type="text" required
                       placeholder="myapp" value="{{ old('name') }}">
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="username">Username</label>
                <input class="input font-mono @error('username') border-rose-400 @enderror"
                       id="username" name="username" type="text" required
                       placeholder="myapp" value="{{ old('username') }}">
                @error('username')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <div class="flex gap-2">
                    <input class="input font-mono @error('password') border-rose-400 @enderror"
                           id="password" name="password" type="text" required
                           placeholder="at least 8 characters" value="{{ old('password') }}">
                    <button type="button" class="btn-ghost shrink-0"
                            onclick="document.getElementById('password').value = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)">Gen</button>
                </div>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="btn-primary">Create database</button>
        </div>

        <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
            The user is allowed on <span class="font-mono text-slate-400">localhost</span> and
            <span class="font-mono text-slate-400">127.0.0.1</span> with full privileges on this
            database only. Connect apps with <span class="font-mono text-slate-400">127.0.0.1</span>
            as the host.
        </p>
    </form>

    {{-- Databases --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-800 px-6 py-4">
            <p class="eyebrow">databases</p>
        </div>

        @if (count($rows) === 0)
            <div class="px-6 py-12 text-center">
                <p class="eyebrow">none yet</p>
                <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                    Create your first database above — credentials stay encrypted in the panel.
                </p>
            </div>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Database</th>
                    <th>User</th>
                    <th>Password</th>
                    <th class="text-right">Size</th>
                    <th class="text-right">Tables</th>
                    <th class="w-56 text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="font-mono text-sm text-slate-300">
                            {{ $row['name'] }}
                            @if ($row['present'] === false)
                                <span class="badge badge-amber ml-2">missing on server</span>
                            @endif
                        </td>
                        <td class="font-mono text-xs text-slate-500">{{ $row['username'] }}</td>
                        <td class="font-mono text-xs text-slate-500">
                            <span id="pw-{{ $row['id'] }}">••••••••</span>
                            <button type="button" class="ml-1 text-amber-400 hover:underline"
                                    onclick="revealPassword({{ $row['id'] }})">show</button>
                        </td>
                        <td class="text-right font-mono text-xs text-slate-500">{{ $row['size'] }}</td>
                        <td class="text-right font-mono text-xs text-slate-500">
                            {{ $row['tables'] === null ? '—' : $row['tables'] }}
                        </td>
                        <td>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn-ghost"
                                        onclick="rotatePassword({{ $row['id'] }}, '{{ $row['username'] }}')">Rotate</button>
                                <form method="POST" action="{{ route('databases.destroy', $row['id']) }}"
                                      onsubmit="return confirm('Drop database {{ $row['name'] }} and its user? ALL DATA WILL BE LOST.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost">Drop</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @foreach ($rows as $row)
        <form id="rotate-form-{{ $row['id'] }}" method="POST"
              action="{{ route('databases.password', $row['id']) }}" class="hidden">
            @csrf
            <input type="hidden" name="password" value="">
        </form>
    @endforeach
@endsection

@section('scripts')
    <script>
        async function revealPassword(id) {
            try {
                const response = await fetch('{{ url('/databases') }}/' + id + '/credentials', {
                    headers: { 'Accept': 'application/json' },
                });
                if (!response.ok) return;
                const data = await response.json();
                document.getElementById('pw-' + id).textContent = data.password;
            } catch (error) {
                /* ignore */
            }
        }

        function rotatePassword(id, username) {
            const password = prompt('New password for ' + username + ':');
            if (!password) return;
            const form = document.getElementById('rotate-form-' + id);
            form.querySelector('input[name="password"]').value = password;
            form.submit();
        }
    </script>
@endsection
