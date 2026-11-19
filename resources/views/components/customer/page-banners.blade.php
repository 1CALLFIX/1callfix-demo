@props(['placement', 'city' => null])
{{--
    Business page banners (owner 2026-11-19): the top banner slot of one "Explore 1CallFix" door's page (Parcel, Shop,
    Food, Hotels, Events, Rental, Second-hand). Admin → Banners → "Where it shows" picks the page; franchise / area
    targeting works as everywhere else (Banner::forPage). Nothing live = nothing rendered, no empty box.
--}}
@php($pageBanners = \App\Models\Banner::forPage($placement, $city))
@if ($pageBanners->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'mt-4']) }} data-testid="page-banners-{{ $placement }}">
        <x-customer.banner-carousel :banners="$pageBanners"
                                    id="{{ str_replace('_', '-', $placement) }}-banners"
                                    label="Offers and announcements"
                                    variant="strip"
                                    :interval="config('banners.mid_rotation_ms')" />
    </div>
@endif
