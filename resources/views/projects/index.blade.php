@extends('layouts.app')

@section('title', 'Projects')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">git · projects</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Projects</h1>
            <p class="mt-1 max-w-xl text-sm text-slate-500">
                Git-based PHP apps: clone, composer, assets, migrations and a Caddy site in one deploy.
            </p>
        </div>
        <a href="{{ route('projects.create') }}" class="btn-primary">＋ New project</a>
    </div>

    <div class="card overflow-hidden">
        @if (count($rows) === 0)
            <div class="px-6 py-12 text-center">
                <p class="eyebrow">no projects yet</p>
                <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                    Add your first repository — the panel clones it, builds it and serves it behind Caddy.
                </p>
                <a href="{{ route('projects.create') }}" class="btn-primary mt-5">＋ New project</a>
            </div>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Project</th>
                    <th>Domain</th>
                    <th>PHP</th>
                    <th>Database</th>
                    <th>Status</th>
                    <th class="w-64 text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    @php($project = $row['project'])
                    <tr>
                        <td>
                            <span class="mono-chip">{{ $project->name }}</span>
                            <p class="mt-1 font-mono text-[11px] text-slate-600">{{ $project->path }}</p>
                        </td>
                        <td class="font-mono text-xs text-slate-500">{{ $project->domain }}</td>
                        <td class="font-mono text-xs text-slate-500">{{ $project->php_version }}</td>
                        <td class="font-mono text-xs text-slate-500">
                            {{ $project->database?->name ?? '—' }}
                        </td>
                        <td>
                            @if ($row['status'] === 'deploying')
                                <span class="badge badge-amber">deploying</span>
                            @elseif ($row['status'] === 'success')
                                <span class="badge badge-green">deployed</span>
                            @elseif ($row['status'] === 'failed')
                                <span class="badge badge-red">failed</span>
                            @else
                                <span class="badge badge-slate">never deployed</span>
                            @endif
                            @if ($project->last_deployed_at)
                                <p class="mt-1 font-mono text-[11px] text-slate-600">
                                    {{ $project->last_deployed_at->diffForHumans() }}
                                </p>
                            @endif
                        </td>
                        <td>
                            <div class="flex justify-end gap-2">
                                @if ($row['install'] !== null)
                                    <a href="{{ route('installs.show', $row['install']) }}" class="btn-ghost">Watch ↗</a>
                                @else
                                    <form method="POST" action="{{ route('projects.deploy', $project) }}">
                                        @csrf
                                        <button type="submit" class="btn-ghost">Deploy</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('projects.destroy', $project) }}"
                                      onsubmit="return confirm('Remove {{ $project->name }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <label class="mr-2 inline-flex cursor-pointer items-center gap-1 align-middle text-[11px] text-slate-500">
                                        <input type="checkbox" name="purge" value="1" class="accent-amber-400">
                                        + files
                                    </label>
                                    <button type="submit" class="btn-ghost">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @if ($row['status'] === 'failed' && $project->last_deploy_message)
                        <tr>
                            <td colspan="6" class="!py-1">
                                <p class="rounded-md border border-rose-900/60 bg-rose-950/30 px-3 py-1.5 font-mono text-[11px] text-rose-400/80">
                                    last error: {{ $project->last_deploy_message }}
                                </p>
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
