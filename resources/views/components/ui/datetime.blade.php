@props([
    'at' => null,
    'franchise' => null,
    'relative' => true,
    'empty' => '—',
])

{{--
    Full date + exact time for any admin list cell: date on top, time below with "2h ago". Shown in the
    franchise's (else the platform's) timezone via App\Services\TimezoneResolver. Hover shows the full stamp.
--}}

@php($at = $at ? \Illuminate\Support\Carbon::parse($at) : null)
@if ($at)
    @php($tz = app(\App\Services\TimezoneResolver::class))
    <div {{ $attributes->merge(['class' => 'leading-tight whitespace-nowrap']) }} title="{{ $tz->format($at, $franchise, 'D, d M Y, h:i A') }}">
        <span class="font-medium text-gray-800 tabular-nums">{{ $tz->format($at, $franchise, 'd M Y') }}</span>
        <span class="block text-xs text-gray-500 tabular-nums">{{ $tz->format($at, $franchise, 'h:i A') }}@if ($relative)<span class="text-gray-400"> · {{ $at->diffForHumans(short: true) }}</span>@endif</span>
    </div>
@else
    <span {{ $attributes->merge(['class' => 'text-gray-400']) }}>{{ $empty }}</span>
@endif
