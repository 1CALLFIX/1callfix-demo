@props(['app' => 'partner', 'variant' => 'pill'])
{{-- Owner 2026-11-26: a partner switching from the Partner app to the Rider app saw the same screen ("You are
     offline") and could not tell which app he was in; online is separate in each app. Every partner app has its own
     colour and icon, shown the same way everywhere (one pattern):
       pill  — the header badge next to the 1CallFix name;
       strip — a coloured bar along the top of the header;
       chip  — "🛵 Delivery Rider app" on the online/offline card.
     Full class names (not built from a colour) so Tailwind keeps them. Hex = the layout's theme-color. --}}
@php
    $apps = [
        'partner' => ['🛠️', 'Partner', 'Service Partner app', 'bg-blue-600', 'text-blue-700'],
        'rider' => ['🛵', 'Rider', 'Delivery Rider app', 'bg-orange-500', 'text-orange-700'],
        'seller' => ['🏪', 'Shop', 'Shop app', 'bg-emerald-600', 'text-emerald-700'],
        'restaurant' => ['🍽️', 'Restaurant', 'Restaurant app', 'bg-rose-600', 'text-rose-700'],
        'hotel' => ['🏨', 'Hotel', 'Hotel app', 'bg-violet-600', 'text-violet-700'],
        'venue' => ['🏠', 'Rental', 'Rental app', 'bg-teal-600', 'text-teal-700'],
        'organiser' => ['🎉', 'Events', 'Events app', 'bg-pink-600', 'text-pink-700'],
    ];
    [$icon, $short, $long, $bg, $text] = $apps[$app] ?? $apps['partner'];
@endphp
@if ($variant === 'strip')
    <div aria-hidden="true" data-partner-app="{{ $app }}" {{ $attributes->class(['h-1', $bg]) }}></div>
@elseif ($variant === 'chip')
    <span data-partner-app="{{ $app }}" {{ $attributes->class(['mb-2 inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-0.5 text-xs font-bold', $text]) }}><span aria-hidden="true">{{ $icon }}</span> {{ $long }}</span>
@else
    <span data-partner-app="{{ $app }}" {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-sm font-bold text-white', $bg]) }}><span aria-hidden="true">{{ $icon }}</span> {{ $short }}</span>
@endif
