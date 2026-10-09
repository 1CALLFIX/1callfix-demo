<div>
    <h1 class="text-xl font-bold tracking-tight">My shifts</h1>
    <p class="text-sm text-slate-500">
        @if ($mode === 'required')
            Choose the shifts you will work. You can only go online during them.
        @elseif ($mode === 'reminder')
            Choose the shifts you plan to work. We remind you before each one starts.
        @else
            Shifts are optional right now. You can still pick the times you usually work.
        @endif
    </p>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    @if ($current)
        <p class="mt-4 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-900">You are in your <strong>{{ $current['shift']->name }}</strong> shift until {{ app(\App\Services\TimezoneResolver::class)->format($current['end'], $franchise, 'h:i A') }}.</p>
    @elseif ($next)
        <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">Next shift: <strong>{{ $next['shift']->name }}</strong>, {{ app(\App\Services\TimezoneResolver::class)->format($next['start'], $franchise, 'D, h:i A') }}.</p>
    @endif

    @if ($shifts->isEmpty())
        <p class="mt-6 text-sm text-slate-500">No shifts have been set up yet.</p>
    @else
        <ul class="mt-6 space-y-3">
            @foreach ($shifts as $shift)
                @php $chosen = in_array($shift->id, $chosenIds, true); @endphp
                <li class="flex items-center justify-between gap-4 rounded-xl border px-4 py-3 {{ $chosen ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-white' }}">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">{{ $shift->name }}</p>
                        <p class="text-xs text-slate-600">{{ \App\Services\Providers\ShiftSchedule::label($shift->start_time) }} – {{ \App\Services\Providers\ShiftSchedule::label($shift->end_time) }}{{ $shift->end_time <= $shift->start_time ? ' (next day)' : '' }} · {{ $shift->daysLabel() }}</p>
                    </div>
                    <button type="button" wire:click="toggle({{ $shift->id }})" aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                            class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-4 text-sm font-semibold {{ $chosen ? 'bg-blue-600 text-white hover:bg-blue-700' : 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50' }}">
                        {{ $chosen ? 'Chosen' : 'Choose' }}
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
