@props([
    'card',
    'currencySymbol' => '₹',
    'compact' => false,
])

{{--
    The service card (Phase C) — one component behind every service tile in
    the customer app: the homepage rails, the category grid, search results,
    offers, and "related services" on the detail page.

    One component, because a card that looks or prices differently depending
    on which screen it is on is a bug waiting to happen. Everything it draws
    comes from the view model CatalogPresenter built; nothing is derived here.

    Fields that have no value are OMITTED, never faked:
      - no cover image  -> a neutral lettered placeholder, not a broken <img>
      - no reviews      -> no star row at all (see x-customer.rating)
      - no saving       -> no strike-through price (see x-customer.price)
      - no duration     -> no clock line
      - no badges       -> no badge row
    A card with none of these still reads as a complete card rather than a
    skeleton with holes in it.

    ── Accessibility ─────────────────────────────────────────────────────────
    The whole card is one <a>, so there is exactly one tab stop and one
    announced link per service instead of four competing ones. The service
    name inside it is the link's accessible name; the badges, rating and
    price are read as its description. Images are decorative (alt="") because
    the name immediately follows them — an alt repeating the service name
    would make a screen reader say it twice.

    ── Performance ───────────────────────────────────────────────────────────
    Every image is lazy-loaded and async-decoded, and carries explicit
    dimensions so the grid does not reflow as images arrive (CLS). Cards
    above the fold on the homepage are few enough that eager-loading them
    would not pay for itself against the cost of loading every rail.
--}}

@php
    $service = $card['service'];
@endphp

<a href="{{ $card['url'] }}"
   {{ $attributes->merge(['class' => 'group flex h-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:border-slate-300 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900']) }}>

    {{-- Media --}}
    <div class="relative aspect-[4/3] w-full overflow-hidden bg-slate-100">
        {{-- A lettered tile keyed to the service's own category colour is
             ALWAYS rendered, with the cover image (when there is one) layered
             on top. Deliberately not an if/else: a stored image path that
             404s — a moved file, a missing `storage:link`, a CDN outage —
             would otherwise leave an empty grey box on the card. This way the
             letter is simply revealed, with no JavaScript and no onerror
             handler, and a catalog with no artwork at all still renders as a
             designed grid. --}}
        <span aria-hidden="true"
              class="absolute inset-0 flex items-center justify-center text-2xl font-bold text-slate-700"
              style="background-color: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($card['category']->color ?? '')) ? $card['category']->color.'26' : '#E2E8F0' }}">
            <x-customer.initial :name="$card['name']" />
        </span>

        @if ($card['image_url'])
            <img src="{{ $card['image_url'] }}"
                 alt=""
                 loading="lazy"
                 decoding="async"
                 width="400" height="300"
                 class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
        @endif

        @if (! empty($card['badges']))
            <span class="absolute left-2 top-2 flex flex-wrap gap-1">
                @foreach (array_slice($card['badges'], 0, 2) as $badge)
                    <x-customer.badge-pill :badge="$badge" />
                @endforeach
            </span>
        @endif
    </div>

    {{-- Body --}}
    <div @class(['flex flex-1 flex-col gap-2', 'p-4' => ! $compact, 'p-3' => $compact])>
        @if ($card['category'])
            <span class="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                {{ $card['category']->name }}
            </span>
        @endif

        <h3 @class(['font-semibold text-slate-900', 'text-base' => ! $compact, 'text-sm' => $compact])>
            {{ $card['name'] }}
        </h3>

        @if (! $compact && $card['description'])
            <p class="line-clamp-2 text-sm leading-relaxed text-slate-600">
                {{ \Illuminate\Support\Str::limit(strip_tags($card['description']), 110) }}
            </p>
        @endif

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <x-customer.rating :rating="$card['rating']" :count="$card['review_count']" />

            @if ($card['duration_mins'])
                <span class="inline-flex items-center gap-1 text-xs text-slate-500">
                    <x-icon name="clock" class="h-3.5 w-3.5" />
                    <span class="sr-only">Typically takes </span>{{ $card['duration_mins'] }} min
                </span>
            @endif
        </div>

        {{-- mt-auto pins the price to the bottom so a row of cards with
             differing description lengths still has its prices aligned. --}}
        <x-customer.price :card="$card" :currency-symbol="$currencySymbol" class="mt-auto pt-1" />
    </div>
</a>
