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

    @if ($status === 'unreachable')
        <div class="card p-6">
            <p class="eyebrow">powerdns not reachable</p>
            <p class="mt-2 max-w-2xl text-sm text-slate-400">
                The PowerDNS API did not answer on
                <span class="font-mono text-slate-300">{{ config('panel.pdns.url') }}</span>.
                Run <span class="font-mono text-amber-400">install-panel.sh</span> (it installs
                PowerDNS, enables the API on localhost and creates the nameserver zone),
                then check <span class="font-mono text-slate-300">systemctl status pdns</span>.
            </p>
        </div>
    @elseif ($status === 'unauthorized')
        <div class="card p-6">
            <p class="eyebrow">api key rejected</p>
            <p class="mt-2 max-w-2xl text-sm text-slate-400">
                PowerDNS answered but rejected the key. Set
                <span class="font-mono text-amber-400">PDNS_API_KEY</span> in
                <span class="font-mono text-slate-300">.env</span> to the
                <span class="font-mono text-slate-300">api-key</span> from
                <span class="font-mono text-slate-300">/etc/powerdns/pdns.conf</span>
                and run <span class="font-mono text-slate-300">php artisan config:clear</span>.
            </p>
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
