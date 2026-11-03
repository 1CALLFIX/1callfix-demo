{{-- Food F1: the veg / egg / non-veg mark Indian customers know — a square with a dot (green veg, amber egg, red
     non-veg; non-veg uses a triangle so it is not told apart by colour alone). --}}
@props(['type' => 'veg'])
@php
    $color = ['veg' => '#15803d', 'egg' => '#b45309', 'non_veg' => '#b91c1c'][$type] ?? '#64748b';
    $label = isset(\App\Support\FoodSettings::FOOD_TYPES[$type]) ? \App\Support\SiteText::get('food.type.'.$type) : ''; // Food F3: English + Telugu
@endphp
<svg {{ $attributes->merge(['class' => 'inline-block h-4 w-4 shrink-0 align-[-2px]']) }} viewBox="0 0 16 16" role="img" aria-label="{{ $label }}">
    <title>{{ $label }}</title>
    <rect x="1" y="1" width="14" height="14" rx="2" fill="#fff" stroke="{{ $color }}" stroke-width="1.6"/>
    @if ($type === 'non_veg')
        <path d="M8 4.2 L11.6 11 H4.4 Z" fill="{{ $color }}"/>
    @else
        <circle cx="8" cy="8" r="3.4" fill="{{ $color }}"/>
    @endif
</svg>
