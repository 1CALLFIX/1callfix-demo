{{-- Owner 2026-11-09: every map link opens the phone's maps app (App\Support\MapLink), not a small map in a browser
     tab. Give lat + lng (mode: directions | place) or a ready Google Maps url. Renders nothing without a location. --}}
@props(['lat' => null, 'lng' => null, 'mode' => 'directions', 'url' => null])
@php
    $href = $url !== null
        ? \App\Support\MapLink::open($url)
        : ($mode === 'place' ? \App\Support\MapLink::place($lat, $lng) : \App\Support\MapLink::directions($lat, $lng));
@endphp
@if ($href)
    <a href="{{ $href }}" @if (\App\Support\MapLink::newTab($href)) target="_blank" rel="noopener" @endif {{ $attributes }}>{{ $slot }}</a>
@endif
