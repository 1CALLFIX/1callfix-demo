@props([
    'cards',
    'currencySymbol' => '₹',
    'labelledBy' => null,
    'compact' => false,
])

{{--
    A horizontal rail of service cards (Phase C).

    Mobile gets a real horizontal scroller with scroll-snap: the alternative
    — a 2-up grid — pushes every later homepage section a screen and a half
    further down, and a rail is the pattern customers already expect from
    every marketplace app. From `sm` up it becomes an ordinary responsive
    grid, because horizontal scrolling on a wide screen hides content behind
    a gesture desktop users do not reach for.

    The scrollbar is hidden visually but the container is still natively
    scrollable — by touch, by trackpad, and by keyboard once a card inside it
    has focus (the browser scrolls focused elements into view on its own).
    No custom key handling is added, so nothing here can trap or fight the
    browser's own focus scrolling.

    `labelledBy` points at the section's own <h2>, which makes the list a
    properly-named group rather than an anonymous run of links.
--}}

@php
    $cards = collect($cards);
@endphp

@if ($cards->isNotEmpty())
    <ul @if ($labelledBy) aria-labelledby="{{ $labelledBy }}" @endif
        {{ $attributes->merge(['class' => 'flex snap-x gap-4 overflow-x-auto pb-2 [scrollbar-width:none] sm:grid sm:grid-cols-2 sm:overflow-visible sm:pb-0 lg:grid-cols-4 [&::-webkit-scrollbar]:hidden']) }}>
        @foreach ($cards as $card)
            <li class="w-64 shrink-0 snap-start sm:w-auto">
                <x-customer.service-card :card="$card" :currency-symbol="$currencySymbol" :compact="$compact" />
            </li>
        @endforeach
    </ul>
@endif
