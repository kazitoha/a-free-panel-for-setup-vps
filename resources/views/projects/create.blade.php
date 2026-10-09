@extends('layouts.app')

@section('title', 'New project')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">git · create</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">New project</h1>
    </div>

    <form method="POST" action="{{ route('projects.store') }}" class="card max-w-2xl p-5">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Project name</label>
                <input class="input font-mono @error('name') border-rose-400 @enderror"
                       id="name" name="name" type="text" required
                       placeholder="myapp" value="{{ old('name') }}">
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="domain">Domain</label>
                <input class="input font-mono @error('domain') border-rose-400 @enderror"
                       id="domain" name="domain" type="text" required
                       placeholder="app.example.com" value="{{ old('domain') }}">
                @error('domain')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <label class="label mt-4" for="repo_url">Repository URL</label>
        <input class="input font-mono @error('repo_url') border-rose-400 @enderror"
               id="repo_url" name="repo_url" type="text" required
               placeholder="https://github.com/user/repo.git" value="{{ old('repo_url') }}">
        @error('repo_url')<p class="field-error">{{ $message }}</p>@enderror

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label" for="branch">Branch</label>
                <input class="input font-mono @error('branch') border-rose-400 @enderror"
                       id="branch" name="branch" type="text"
                       placeholder="main" value="{{ old('branch', 'main') }}">
                @error('branch')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="php_version">PHP version</label>
                <select class="input" id="php_version" name="php_version">
                    @foreach ($phpVersions as $version)
                        <option value="{{ $version }}"
                            @selected(old('php_version', $defaultPhp) === $version)>
                            PHP {{ $version }}
                        </option>
                    @endforeach
                </select>
                @error('php_version')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="database_id">Database <span class="text-slate-600">(optional)</span></label>
                <select class="input" id="database_id" name="database_id">
                    <option value="">— none —</option>
                    @foreach ($databases as $database)
                        <option value="{{ $database->id }}" @selected((int) old('database_id') === $database->id)>
                            {{ $database->name }}
                        </option>
                    @endforeach
                </select>
                @error('database_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-400">
            <input type="checkbox" name="dns" value="1" @checked(old('dns', true)) class="accent-amber-400">
            Create the DNS zone in PowerDNS when it does not exist yet
        </label>

        <div class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
            Deploy pipeline:
            <span class="font-mono text-slate-400">git clone/pull → composer install → npm build →
            .env + APP_KEY → migrate → storage permissions → Caddy site</span>.
            When a database is selected its credentials are written into the project's
            <span class="font-mono text-slate-400">.env</span>. The directory becomes
            <span class="font-mono text-slate-400">/var/www/&lt;name&gt;</span>.
        </div>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="btn-primary">Create &amp; deploy</button>
            <a href="{{ route('projects.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
