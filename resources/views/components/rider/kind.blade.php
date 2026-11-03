@props(['kind' => 'parcel', 'size' => 'sm'])
@php
    /*
     | Rider app 2026-11-04: one rider takes parcel, shop, food and return-pickup jobs, so every offer, job and history
     | line carries the same coloured label — the rider always knows which kind of job it is, at a glance.
     */
    [$label, $classes] = match ($kind) {
        'food' => ['Food', 'bg-orange-100 text-orange-800 ring-orange-200'],
        'shop' => ['Shop', 'bg-violet-100 text-violet-800 ring-violet-200'],
        'return' => ['Return pickup', 'bg-slate-200 text-slate-800 ring-slate-300'],
        default => ['Parcel', 'bg-blue-100 text-blue-800 ring-blue-200'],
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full font-bold uppercase tracking-wide ring-1', $classes,
    'px-2 py-0.5 text-[11px]' => $size === 'sm', 'px-3 py-1 text-xs' => $size !== 'sm']) }} data-kind="{{ $kind }}">{{ $label }}</span>
