@props([
    'cards',
    'currencySymbol' => '₹',
    'labelledBy' => null,
    'compact' => false,
])

{{--
    A horizontal rail of service cards (Phase C; peek revision).

    This is a horizontal, scroll-snapped rail at EVERY breakpoint — it never
    becomes a wrapped grid. The next card is always partially visible past the
    right edge (a "carousel peek" / "partial reveal"): the clipped card is the
    affordance that tells the customer the row scrolls, with no arrows or "swipe"
    label taking up space. This matches the pattern every large services
    marketplace uses for these rows.

    Card widths are a percentage of the rail, stepped up per breakpoint, and
    always chosen so a fraction of the following card shows:
      - base   ~2 cards + peek
      - sm     ~3 cards + peek
      - lg     ~4 cards + peek
      - xl     ~5 cards + peek

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
        {{ $attributes->merge(['class' => 'flex snap-x gap-4 overflow-x-auto pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden']) }}>
        @foreach ($cards as $card)
            <li class="w-[47%] shrink-0 grow-0 snap-start sm:w-[31%] lg:w-[23.5%] xl:w-[19%]">
                <x-customer.service-card :card="$card" :currency-symbol="$currencySymbol" :compact="$compact" />
            </li>
        @endforeach
    </ul>
@endif
