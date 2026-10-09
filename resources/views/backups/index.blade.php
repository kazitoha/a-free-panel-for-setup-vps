@extends('layouts.app')

@section('title', 'Backups')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">schedules · archives</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Backups</h1>
            <p class="mt-1 max-w-xl text-sm text-slate-500">
                Verified <span class="font-mono text-slate-400">.sql.gz</span> dumps and
                <span class="font-mono text-slate-400">.tar.gz</span> project archives in
                <span class="font-mono text-slate-400">{{ $storageDir }}</span>.
                The scheduler runs every minute and picks up each schedule at its slots.
            </p>
        </div>
    </div>

    @if ($filesError)
        <div class="mb-6 rounded-md border border-amber-900/60 bg-amber-950/30 p-3">
            <p class="text-xs text-amber-300">{{ $filesError }}</p>
        </div>
    @endif

    {{-- New schedule --}}
    <form method="POST" action="{{ route('backups.store') }}" class="card p-5">
        @csrf
        <p class="eyebrow">new schedule</p>

        @if ($targets->isEmpty())
            <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
                Every database and project already has a schedule. Create another database
                or project first — one schedule per target.
            </p>
        @else
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="target">Target</label>
                    <select class="input font-mono @error('target') border-rose-400 @enderror"
                            id="target" name="target" required>
                        <option value="">Choose…</option>
                        @foreach ($targets as $target)
                            <option value="{{ $target['value'] }}" @selected(old('target') === $target['value'])>
                                {{ $target['label'] }}
                            </option>
                        @endforeach
                    </select>
                    @error('target')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="times_per_day">Runs per day</label>
                    <input class="input font-mono @error('times_per_day') border-rose-400 @enderror"
                           id="times_per_day" name="times_per_day" type="number"
                           min="1" max="24" required
                           value="{{ old('times_per_day', 1) }}">
                    @error('times_per_day')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="retention_days">Keep for (days)</label>
                    <input class="input font-mono @error('retention_days') border-rose-400 @enderror"
                           id="retention_days" name="retention_days" type="number"
                           min="1" max="90" required
                           value="{{ old('retention_days', $retentionDefault) }}">
                    @error('retention_days')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-4 flex gap-3">
                <button type="submit" class="btn-primary">Create schedule</button>
            </div>
        @endif
    </form>

    {{-- Schedules --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-800 px-6 py-4">
            <p class="eyebrow">schedules</p>
        </div>

        @if (count($schedules) === 0)
            <div class="px-6 py-12 text-center">
                <p class="eyebrow">none yet</p>
                <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                    Schedule your first backup above — the panel runs it from cron and
                    verifies every archive.
                </p>
            </div>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Target</th>
                    <th class="text-right">Runs / day</th>
                    <th class="text-right">Keep</th>
                    <th>Next run</th>
                    <th>Last result</th>
                    <th class="w-72 text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($schedules as $row)
                    @php($schedule = $row['schedule'])
                    <tr>
                        <td class="font-mono text-sm text-slate-300">
                            {{ $row['label'] }}
                            <span class="badge badge-slate ml-2">{{ $schedule->target_type }}</span>
                            @if ($row['missing'])
                                <span class="badge badge-amber ml-2">target missing</span>
                            @elseif (! $schedule->enabled)
                                <span class="badge badge-slate ml-2">paused</span>
                            @endif
                        </td>
                        <td class="text-right font-mono text-xs text-slate-500">{{ $schedule->times_per_day }}</td>
                        <td class="text-right font-mono text-xs text-slate-500">{{ $schedule->retention_days }} d</td>
                        <td class="font-mono text-xs text-slate-500">
                            {{ $schedule->enabled && $schedule->next_run_at ? $schedule->next_run_at->format('D M d, H:i') : '—' }}
                        </td>
                        <td>
                            @if ($schedule->last_status === 'success')
                                <span class="badge badge-green">ok</span>
                            @elseif ($schedule->last_status === 'failed')
                                <span class="badge badge-red"
                                      @if ($schedule->last_error) title="{{ $schedule->last_error }}" @endif>failed</span>
                            @else
                                <span class="badge badge-slate">never</span>
                            @endif
                            @if ($schedule->last_run_at)
                                <span class="ml-2 font-mono text-xs text-slate-500">
                                    {{ $schedule->last_run_at->format('M d, H:i') }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('backups.run', $schedule) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost">Run now</button>
                                </form>
                                <form method="POST" action="{{ route('backups.toggle', $schedule) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost">
                                        {{ $schedule->enabled ? 'Pause' : 'Resume' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('backups.destroy', $schedule) }}"
                                      onsubmit="return confirm('Remove this schedule? Existing archives are kept.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost">Remove</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Archives --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-800 px-6 py-4">
            <p class="eyebrow">archives on disk</p>
        </div>

        @if ($files === null || count($files) === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm text-slate-500">
                    {{ $files === null ? 'The backup directory could not be listed.' : 'No archives yet — run a schedule above.' }}
                </p>
            </div>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Scope</th>
                    <th>File</th>
                    <th class="text-right">Size</th>
                    <th class="text-right">Written</th>
                </tr>
                </thead>
                <tbody>
                @foreach (array_slice($files, 0, 25) as $file)
                    <tr>
                        <td>
                            <span class="badge {{ $file['scope'] === 'databases' ? 'badge-green' : 'badge-slate' }}">
                                {{ $file['scope'] }}
                            </span>
                        </td>
                        <td class="font-mono text-xs text-slate-400">{{ $file['file'] }}</td>
                        <td class="text-right font-mono text-xs text-slate-500">{{ $file['size_label'] }}</td>
                        <td class="text-right font-mono text-xs text-slate-500">
                            {{ \Illuminate\Support\Carbon::createFromTimestamp($file['mtime'] ?? 0)->format('M d, H:i') }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
