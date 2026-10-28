@props([
    'at' => null,
    'franchise' => null,
    'outcome' => null,  // completed | cancelled | rejected | failed | refunded | ... — labels the close
    'open' => false,    // true while the item is still running: shows "In progress" instead of a dash
    'openLabel' => 'In progress',
])

{{-- The "Completed" column: when an item closed, and how. Pairs with <x-ui.datetime> (created) and <x-ui.tat>. --}}

@php
    $tone = match ($outcome) {
        'completed', 'delivered', 'approved', 'captured', 'paid', 'processed', 'resolved', 'refunded', 'success', 'successful', 'settled', 'rewarded', 'applied' => 'text-green-700',
        'cancelled', 'expired' => 'text-slate-500',
        'rejected', 'failed', 'refused' => 'text-red-600',
        default => 'text-gray-500',
    };
@endphp

@php($at = $at ? \Illuminate\Support\Carbon::parse($at) : null)
@if ($at)
    @php($tz = app(\App\Services\TimezoneResolver::class))
    <div class="leading-tight whitespace-nowrap" title="{{ $tz->format($at, $franchise, 'D, d M Y, h:i A') }}">
        <span class="font-medium text-gray-800 tabular-nums">{{ $tz->format($at, $franchise, 'd M Y') }}</span>
        <span class="block text-xs text-gray-500 tabular-nums">{{ $tz->format($at, $franchise, 'h:i A') }}@if ($outcome)<span class="{{ $tone }}"> · {{ ucfirst(str_replace('_', ' ', $outcome)) }}</span>@endif</span>
    </div>
@elseif ($open)
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-medium text-amber-700">
        <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-amber-500"></span></span>
        {{ $openLabel }}
    </span>
@else
    <span class="text-gray-400">—</span>
@endif
