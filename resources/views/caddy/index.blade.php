@extends('layouts.app')

@section('title', 'Sites')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">caddy · /etc/caddy/sites</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Sites</h1>
            <p class="mt-1 max-w-xl text-sm text-slate-500">
                HTTPS vhosts served by Caddy. Each site is a separate file — validated,
                backed up and reloaded with automatic rollback.
            </p>
        </div>
        <a href="{{ route('caddy.create') }}" class="btn-primary">＋ New site</a>
    </div>

    <div class="card overflow-hidden">
        @if (count($sites) === 0)
            <div class="px-6 py-12 text-center">
                <p class="eyebrow">no sites yet</p>
                <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                    Create your first site and Caddy will issue a Let's Encrypt
                    certificate for it automatically once DNS points here.
                </p>
                <a href="{{ route('caddy.create') }}" class="btn-primary mt-5">＋ New site</a>
            </div>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Address</th>
                    <th>Type</th>
                    <th>File</th>
                    <th class="w-32 text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($sites as $site)
                    <tr>
                        <td>
                            <div class="flex flex-wrap items-center gap-2">
                                @forelse ($site['addresses'] as $address)
                                    <span class="mono-chip">{{ $address }}</span>
                                @empty
                                    <span class="text-slate-600">—</span>
                                @endforelse
                            </div>
                        </td>
                        <td>
                            @if ($site['reserved'])
                                <span class="badge badge-slate">managed by panel</span>
                            @elseif (\App\Support\SiteSpec::parse($site['content']) !== null)
                                <span class="badge">
                                    {{ \App\Support\SiteSpec::parse($site['content'])['type'] }}
                                </span>
                            @else
                                <span class="badge badge-slate">custom</span>
                            @endif
                        </td>
                        <td class="font-mono text-xs text-slate-500">{{ $site['name'] }}.caddy</td>
                        <td>
                            <div class="flex justify-end gap-2">
                                @if ($site['reserved'])
                                    <span class="text-xs text-slate-600">module</span>
                                @else
                                    <a href="{{ route('caddy.edit', $site['name']) }}"
                                       class="btn-ghost">Edit</a>
                                    <form method="POST" action="{{ route('caddy.destroy', $site['name']) }}"
                                          onsubmit="return confirm('Delete {{ $site['name'] }}? Caddy will reload without it.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-ghost">Del</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
