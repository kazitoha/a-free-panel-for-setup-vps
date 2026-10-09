@extends('layouts.app')

@section('title', 'New mail domain')

@section('content')
    <div class="mb-6">
        <p class="eyebrow">stalwart · create</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">New mail domain</h1>
    </div>

    <form method="POST" action="{{ route('mail.store') }}" class="card max-w-xl p-5">
        @csrf

        <label class="label" for="domain">Domain</label>
        <input class="input @error('domain') border-rose-400 @enderror"
               id="domain" name="domain" type="text" required
               placeholder="example.com" value="{{ old('domain') }}">
        @error('domain')<p class="field-error">{{ $message }}</p>@enderror

        <label class="label mt-4" for="ip">Server IP (apex A record)</label>
        <input class="input @error('ip') border-rose-400 @enderror"
               id="ip" name="ip" type="text"
               placeholder="203.0.113.10" value="{{ old('ip', $defaultIp) }}">
        @error('ip')<p class="field-error">{{ $message }}</p>@enderror

        <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
            The zone is created in PowerDNS first, then Stalwart publishes
            MX → <span class="font-mono text-slate-400">{{ $hostname }}</span>,
            SPF, DMARC and a fresh DKIM key automatically (PowerDns provider).
            Certificates use DNS-01, so no port conflicts with Caddy.
        </p>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="btn-primary">Create mail domain</button>
            <a href="{{ route('mail.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
