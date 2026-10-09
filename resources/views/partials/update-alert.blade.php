@isset($panelUpdate)
    @if ($panelUpdate['update_available'])
        <div class="mb-6 flex flex-wrap items-start gap-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3"
             role="status">
            <span class="text-lg leading-6 text-amber-400" aria-hidden="true">⇪</span>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-amber-200">
                    Panel update available — {{ $panelUpdate['behind'] }} commit(s) behind {{ $panelUpdate['branch'] }}.
                </p>

                @if ($panelUpdate['subject'])
                    <p class="mt-1 truncate font-mono text-xs text-amber-400/80">{{ $panelUpdate['subject'] }}</p>
                @endif

                @if ($panelUpdate['checked_at'])
                    <p class="mt-1 font-mono text-[11px] uppercase tracking-widest text-amber-500/70">
                        checked {{ \Illuminate\Support\Carbon::parse($panelUpdate['checked_at'])->diffForHumans() }}
                    </p>
                @endif
            </div>

            <div class="flex shrink-0 flex-wrap gap-2">
                <form method="POST" action="{{ route('update.run') }}"
                      onsubmit="return confirm('Apply the panel update now? Services will restart.')">
                    @csrf
                    <button type="submit" class="btn-primary">Update now</button>
                </form>

                <form method="POST" action="{{ route('update.check') }}">
                    @csrf
                    <button type="submit" class="btn-ghost">Check again</button>
                </form>
            </div>
        </div>
    @endif
@endisset
