@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
])

{{--
    Phase 21 item TECH-6 (Admin UI design system, first increment). A single
    button primitive standing in for the hand-copied class strings repeated
    across every admin screen so far (e.g. "bg-slate-900 text-white px-6
    py-2 rounded text-sm font-medium hover:bg-slate-800" — Badges\Manage,
    Flash Sales\Manage, and a dozen others, all typed out fresh each time).
    Variant colors match what's already in use, not invented: slate-900 for
    the dark "primary" action, a plain white/border button for secondary,
    red-600 for destructive, and the existing "text-blue-600 hover:underline"
    link-style action for row-level table actions ("ghost"). Renders an <a>
    instead of a <button> when $href is given, for screens that link to a
    show/detail page rather than firing a Livewire action — same classes
    either way so the two look identical.
--}}

@php
    $variants = [
        'primary' => 'bg-slate-900 text-white hover:bg-slate-800 focus-visible:outline-slate-900',
        'secondary' => 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus-visible:outline-gray-400',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600',
        'ghost' => 'bg-transparent text-blue-600 hover:underline px-0 py-0 font-normal',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-6 py-2.5 text-sm',
    ];

    // 'ghost' reuses the existing link-action look (no padding/background),
    // so it only ever takes the size's text-size class, never its padding.
    $ghostTextSizes = ['sm' => 'text-xs', 'md' => 'text-sm', 'lg' => 'text-sm'];

    $base = 'inline-flex items-center justify-center gap-1.5 rounded font-medium transition disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2';
    $variantClass = $variants[$variant] ?? $variants['primary'];
    $sizeClass = $variant === 'ghost'
        ? ($ghostTextSizes[$size] ?? $ghostTextSizes['md'])
        : ($sizes[$size] ?? $sizes['md']);
    $classes = trim("{$base} {$variantClass} {$sizeClass}");
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
