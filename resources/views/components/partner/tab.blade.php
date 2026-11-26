@props(['active' => false, 'color' => 'blue', 'count' => null, 'icon' => null, 'href' => null])
{{-- Owner 2026-11-26 ("make sure it must be easy"; "if all the apps follow the same pattern it is easier to find any
     issue"): the ONE tab used on every partner app's work screen (service Jobs, shop/restaurant Orders, hotel
     Bookings, hall Bookings, house Visits). Big (44 px), its own colour and icon per step, the count as a badge,
     and the last tab is always 🕘 past work in green. A link with `href`, otherwise a button (wire:click).
     Guarded by tests/Feature/PartnerWeb/PartnerTabsPatternTest.php. Full class names (not built from $color) so
     Tailwind keeps them. --}}
@php
    $on = [
        'amber' => 'border-amber-500 bg-amber-500 text-white',
        'orange' => 'border-transparent bg-orange-500 text-white',
        'blue' => 'border-blue-600 bg-blue-600 text-white',
        'violet' => 'border-violet-600 bg-violet-600 text-white',
        'emerald' => 'border-emerald-600 bg-emerald-600 text-white',
        'rose' => 'border-rose-600 bg-rose-600 text-white',
        'slate' => 'border-slate-700 bg-slate-700 text-white',
    ][$color] ?? 'border-blue-600 bg-blue-600 text-white';
    $classes = [
        'inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border px-4 text-sm font-semibold transition',
        $on => $active,
        'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' => ! $active,
    ];
@endphp
@if ($href)
<a href="{{ $href }}" wire:navigate data-partner-tab @if ($active) aria-current="page" @endif {{ $attributes->class($classes) }}>
@else
<button type="button" role="tab" data-partner-tab aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
@endif
    @if ($icon)<span aria-hidden="true">{{ $icon }}</span>@endif
    <span>{{ $slot }}</span>
    @if ($count)
        <span @class(['rounded-full px-1.5 text-xs font-bold', 'bg-white text-slate-900' => $active, 'bg-rose-600 text-white' => ! $active])>{{ $count }}</span>
    @endif
@if ($href)
</a>
@else
</button>
@endif
