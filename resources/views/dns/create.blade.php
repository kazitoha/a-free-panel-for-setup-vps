@extends('layouts.app')

@section('title', 'New DNS zone')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">powerdns · create</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">New DNS zone</h1>
    </div>

    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
        {{-- Single zone --}}
        <form method="POST" action="{{ route('dns.store') }}" class="card p-5">
            @csrf
            <p class="eyebrow">single zone</p>

            <label class="label mt-4" for="domain">Domain</label>
            <input class="input @error('domain') border-rose-400 @enderror"
                   id="domain" name="domain" type="text" required
                   placeholder="example.com" value="{{ old('domain') }}">
            @error('domain')<p class="field-error">{{ $message }}</p>@enderror

            <label class="label mt-4" for="ip">Server IP (apex A record)</label>
            <input class="input @error('ip') border-rose-400 @enderror"
                   id="ip" name="ip" type="text"
                   placeholder="203.0.113.10" value="{{ old('ip', $defaultIp) }}">
            @error('ip')<p class="field-error">{{ $message }}</p>@enderror

            <label class="mt-4 flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" name="wildcard" value="1" class="h-4 w-4 accent-amber-400"
                       @checked(old('wildcard'))>
                Add wildcard <span class="mono-chip">*.{old('domain', 'example.com')}</span> record
            </label>

            <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
                Created automatically: SOA, NS →
                <span class="font-mono text-slate-400">ns1.{{ $nsDomain }}</span> /
                <span class="font-mono text-slate-400">ns2.{{ $nsDomain }}</span>,
                and the A record above. Delegation NS at your registrar must point
                to those nameservers.
            </p>

            <button type="submit" class="btn-primary mt-4">Create zone</button>
        </form>

        {{-- Batch import --}}
        <form method="POST" action="{{ route('dns.import') }}" class="card p-5">
            @csrf
            <p class="eyebrow">batch import · customer domains</p>

            <label class="label mt-4" for="domains">Domains (comma or newline separated)</label>
            <textarea class="input h-36 resize-y font-mono text-xs @error('domains') border-rose-400 @enderror"
                      id="domains" name="domains" required
                      placeholder="shop1.com, shop2.com&#10;client3.net">{{ old('domains') }}</textarea>
            @error('domains')<p class="field-error">{{ $message }}</p>@enderror

            <label class="label mt-4" for="import-ip">Server IP (apex A record)</label>
            <input class="input" id="import-ip" name="ip" type="text"
                   placeholder="203.0.113.10" value="{{ old('ip', $defaultIp) }}">

            <label class="mt-4 flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" name="wildcard" value="1" class="h-4 w-4 accent-amber-400"
                       @checked(old('wildcard'))>
                Wildcard A record for every domain
            </label>

            <button type="submit" class="btn-primary mt-4">Import zones</button>
        </form>
    </div>

    <div class="mt-6">
        <a href="{{ route('dns.index') }}" class="btn-ghost">← Back to zones</a>
    </div>
@endsection
