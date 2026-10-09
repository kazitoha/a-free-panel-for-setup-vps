@extends('layouts.app')

@section('title', 'Cron jobs')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">schedules · jobs</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">Cron jobs</h1>
            <p class="mt-1 max-w-xl text-sm text-slate-500">
                Jobs run as root from
                <span class="font-mono text-slate-400">{{ $cronFile }}</span>,
                rewritten by the panel on every change. The scheduler tick (backup runs)
                is always installed as the first line.
            </p>
        </div>
    </div>

    @php($cronInstalled = is_file($cronFile))
    @if (! $cronInstalled)
        <div class="mb-6 rounded-md border border-amber-900/60 bg-amber-950/30 p-3">
            <p class="text-xs text-amber-300">
                <span class="font-mono">{{ $cronFile }}</span> does not exist on this host —
                jobs below are saved but will not run until it is created. Run
                <span class="font-mono text-amber-200">php artisan panel:cron:sync</span> as root
                (or install-panel.sh) to create it.
            </p>
        </div>
    @endif

    {{-- New job --}}
    <form method="POST" action="{{ route('cron.store') }}" class="card p-5">
        @csrf
        <p class="eyebrow">new cron job</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Name</label>
                <input class="input font-mono @error('name') border-rose-400 @enderror"
                       id="name" name="name" type="text" required maxlength="60"
                       placeholder="Restart Caddy at night" value="{{ old('name') }}">
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="command">Command</label>
                <input class="input font-mono @error('command') border-rose-400 @enderror"
                       id="command" name="command" type="text" required maxlength="300"
                       placeholder="/usr/local/panel/run panel:backup:run 1"
                       value="{{ old('command') }}">
                @error('command')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-4">
            <div>
                <label class="label" for="frequency">Frequency</label>
                <select class="input font-mono @error('frequency') border-rose-400 @enderror"
                        id="frequency" name="frequency">
                    @foreach ($frequencies as $value => $label)
                        <option value="{{ $value }}" @selected(old('frequency', 'daily') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('frequency')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="time">Time (HH:MM)</label>
                <input class="input font-mono @error('time') border-rose-400 @enderror"
                       id="time" name="time" type="time" required
                       value="{{ old('time', '03:00') }}">
                @error('time')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div id="every-hours-field" class="{{ old('frequency') === 'every_hours' ? '' : 'hidden' }}">
                <label class="label" for="every_hours">Every N hours</label>
                <input class="input font-mono @error('every_hours') border-rose-400 @enderror"
                       id="every_hours" name="every_hours" type="number" min="1" max="23"
                       value="{{ old('every_hours', 6) }}">
                @error('every_hours')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div id="weekday-field" class="{{ old('frequency') === 'weekly' ? '' : 'hidden' }}">
                <label class="label" for="weekday">Weekday</label>
                <select class="input font-mono @error('weekday') border-rose-400 @enderror"
                        id="weekday" name="weekday">
                    @foreach ($weekdays as $value => $label)
                        <option value="{{ $value }}" @selected(old('weekday', '1') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('weekday')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="btn-primary">Add job</button>
        </div>

        <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
            Only three command forms are allowed:
            <span class="font-mono text-slate-400">/usr/local/panel/run panel:*</span>,
            <span class="font-mono text-slate-400">systemctl &lt;start|stop|restart|reload&gt; &lt;unit&gt;</span>
            and <span class="font-mono text-slate-400">reboot</span>.
            Examples: <span class="font-mono text-slate-400">/usr/local/panel/run panel:backup:run 1</span>,
            <span class="font-mono text-slate-400">systemctl restart caddy</span>.
        </p>
    </form>

    {{-- Jobs --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-800 px-6 py-4">
            <p class="eyebrow">jobs</p>
        </div>

        @if (count($jobs) === 0)
            <div class="px-6 py-12 text-center">
                <p class="eyebrow">none yet</p>
                <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">
                    Add your first job above. The scheduler tick line exists even with
                    no jobs, so backup schedules work out of the box.
                </p>
            </div>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Schedule</th>
                    <th>Command</th>
                    <th>Last run</th>
                    <th class="w-64 text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($jobs as $job)
                    <tr @if (! $job->enabled) class="opacity-60" @endif>
                        <td class="text-sm text-slate-300">
                            {{ $job->name }}
                            @if (! $job->enabled)
                                <span class="badge badge-slate ml-2">off</span>
                            @endif
                        </td>
                        <td class="font-mono text-xs text-slate-500">
                            {{ implode(' ', $job->scheduleFields()) }}
                        </td>
                        <td class="max-w-xs truncate font-mono text-xs text-slate-400"
                            title="{{ $job->command }}">{{ $job->command }}</td>
                        <td>
                            @if ($job->last_status === 'ok')
                                <span class="badge badge-green">ok</span>
                            @elseif ($job->last_status === 'failed')
                                <span class="badge badge-red"
                                      @if ($job->last_output) title="{{ $job->last_output }}" @endif>failed</span>
                            @else
                                <span class="badge badge-slate">never</span>
                            @endif
                            @if ($job->last_run_at)
                                <span class="ml-2 font-mono text-xs text-slate-500">
                                    {{ $job->last_run_at->format('M d, H:i') }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('cron.run', $job) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost">Run now</button>
                                </form>
                                <form method="POST" action="{{ route('cron.toggle', $job) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost">
                                        {{ $job->enabled ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('cron.destroy', $job) }}"
                                      onsubmit="return confirm('Delete this cron job?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection

@section('scripts')
    <script>
        const frequency = document.getElementById('frequency');
        const everyHours = document.getElementById('every-hours-field');
        const weekday = document.getElementById('weekday-field');

        function syncFrequencyFields() {
            everyHours.classList.toggle('hidden', frequency.value !== 'every_hours');
            weekday.classList.toggle('hidden', frequency.value !== 'weekly');
        }

        frequency.addEventListener('change', syncFrequencyFields);
        syncFrequencyFields();
    </script>
@endsection
