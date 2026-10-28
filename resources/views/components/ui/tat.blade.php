@props([
    'from' => null,
    'to' => null,
    'open' => false,      // still running: show the time so far, counting from $from to now
    'outcome' => null,    // cancelled / rejected / failed close in grey, everything else in green
])

{{--
    Turnaround time (TAT): created → closed, as a pill ("3h 05m"). While an item is still open it shows the time
    so far in amber. Formatting lives in App\Support\Turnaround so exports and analytics read the same number.
--}}

@php
    $done = $from && $to;
    $running = $from && ! $to && $open;
    $seconds = $done || $running ? \App\Support\Turnaround::seconds($from, $to) : null;
    $muted = in_array($outcome, ['cancelled', 'rejected', 'failed', 'expired', 'refused'], true);
    $class = $running ? 'bg-amber-50 text-amber-800 ring-amber-200'
        : ($muted ? 'bg-slate-50 text-slate-600 ring-slate-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200');
@endphp

@if ($seconds !== null)
    <span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ring-1 ring-inset {$class}"]) }}
          title="{{ $running ? 'Open for ' : 'Took ' }}{{ \App\Support\Turnaround::format($seconds) }}{{ $running ? ' so far' : '' }}">
        <x-icon name="clock" class="h-3 w-3 shrink-0" />
        {{ \App\Support\Turnaround::format($seconds) }}@if ($running)<span class="font-normal"> so far</span>@endif
    </span>
@else
    <span class="text-gray-400">—</span>
@endif
