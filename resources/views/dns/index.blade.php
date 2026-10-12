@extends('layouts.app')

@section('title', 'DNS')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">powerdns · zones</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">DNS zones</h1>
            <p class="mt-1 max-w-xl text-sm text-slate-500">
                Authoritative DNS served by PowerDNS. New zones get SOA + NS records
                for <span class="font-mono text-slate-400">{{ $nsDomain }}</span> automatically.
            </p>
        </div>
        @if ($status === 'ok')
            <a href="{{ route('dns.create') }}" class="btn-primary">＋ New zone</a>
        @endif
    </div>

    @if ($status !== 'ok')
        <div class="card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="eyebrow">{{ $status === 'unauthorized' ? 'api key rejected' : 'powerdns not installed' }}</p>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-400">
                        @if ($status === 'unauthorized')
                            PowerDNS answered but rejected the key, so zones cannot be edited.
                            Installing it again re-reads the server, repairs the API key and keeps
                            the zones that already exist.
                        @else
                            The PowerDNS API did not answer on
                            <span class="font-mono text-slate-300">{{ config('panel.pdns.url') }}</span>.
                            Installing sets up the authoritative server, its database, the localhost
                            API and the nameserver zone — MySQL first, when it is missing too.
                        @endif
                    </p>
                </div>
                @if ($active)
                    <a href="{{ route('installs.show', $active) }}" class="btn-primary">Watch install ↗</a>
                @else
                    <form method="POST" action="{{ route('dns.install') }}"
                          onsubmit="return confirm('Install PowerDNS now?');">
                        @csrf
                        <button type="submit" class="btn-primary">⬇ Install PowerDNS</button>
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
    @else
        <div class="card overflow-hidden">
            @if (count($zones) === 0)
                <div class="px-6 py-12 text-center">
                    <p class="eyebrow">no zones yet</p>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                        Add your first domain — or import many customer domains at once.
                    </p>
                    <a href="{{ route('dns.create') }}" class="btn-primary mt-5">＋ New zone</a>
                </div>
            @else
                <table class="table">
                    <thead>
                    <tr>
                        <th>Zone</th>
                        <th>Kind</th>
                        <th>Serial</th>
                        <th class="w-40 text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($zones as $zone)
                        <tr>
                            <td>
                                <a href="{{ route('dns.show', $zone['name']) }}"
                                   class="mono-chip hover:border-amber-400 hover:text-amber-400">
                                    {{ $zone['name'] }}
                                </a>
                                @if ($zone['protected'])
                                    <span class="badge badge-slate ml-2">protected</span>
                                @endif
                            </td>
                            <td><span class="badge">{{ $zone['kind'] }}</span></td>
                            <td class="font-mono text-xs text-slate-500">{{ $zone['serial'] }}</td>
                            <td>
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('dns.show', $zone['name']) }}" class="btn-ghost">Open</a>
                                    @unless ($zone['protected'])
                                        <form method="POST" action="{{ route('dns.destroy', $zone['name']) }}"
                                              onsubmit="return confirm('Delete zone {{ $zone['name'] }} and ALL its records?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-ghost">Del</button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
@endsection
