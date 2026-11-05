@props([
    'cards',
    'currencySymbol' => '₹',
    'labelledBy' => null,
    'compact' => false,
    'tile' => false,
    'autoscroll' => false,
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
    {{-- `tile` (homepage, owner 2026-11-05): small tiles, three and a bit across a phone. `autoscroll`: the row drifts
         slowly left to right and back, and stops for good once the visitor touches or scrolls it themselves (and
         never moves for people who asked their phone for less motion). --}}
    <ul @if ($labelledBy) aria-labelledby="{{ $labelledBy }}" @endif
        @if ($autoscroll)
            x-data="{ dir: 1, timer: null, stop() { clearInterval(this.timer); this.timer = null } }"
            x-init="if (! window.matchMedia('(prefers-reduced-motion: reduce)').matches) { timer = setInterval(() => {
                const max = $el.scrollWidth - $el.clientWidth; if (max <= 0) return;
                if ($el.scrollLeft >= max - 1) dir = -1; else if ($el.scrollLeft <= 0) dir = 1;
                $el.scrollLeft += dir; }, 30) }"
            x-on:pointerdown="stop()" x-on:wheel="stop()" x-on:focusin="stop()" x-on:mouseenter="stop()"
            data-autoscroll
        @endif
        {{ $attributes->merge(['class' => ($tile ? 'flex gap-3' : 'flex snap-x gap-4').' overflow-x-auto pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden']) }}>
        @foreach ($cards as $card)
            <li @class([
                'shrink-0 grow-0',
                'w-[30%] sm:w-[22%] lg:w-[15.5%] xl:w-[13%]' => $tile,
                'w-[47%] snap-start sm:w-[31%] lg:w-[23.5%] xl:w-[19%]' => ! $tile,
            ])>
                <x-customer.service-card :card="$card" :currency-symbol="$currencySymbol" :compact="$compact" :tile="$tile" />
            </li>
        @endforeach
    </ul>
@endif
