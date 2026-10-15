@props([
    'category',
    'count' => null,
    // 'card'    — the self-contained bordered tile (default): the category
    //             explorer grid and search results, where each tile stands
    //             alone in a cell and carries a service count.
    // 'compact' — a borderless, centred, icon-over-label tile for a dense
    //             grid inside another surface (the homepage hero's category
    //             card). No count line; the grid it sits in supplies the
    //             frame. This is the shape every large services marketplace
    //             uses for its homepage category shortcuts.
    'variant' => 'card',
])

{{--
    A category shortcut tile (Phase C; variant added for the homepage hero
    grid). The category explorer grid, search results and the homepage all
    render categories through this one component so they cannot drift into
    different-looking representations of the same row — `variant` only
    changes the framing, never what a category IS.

    Image, colour and name all come from the `service_categories` row.
    `image_url` already resolves both storage shapes the column carries (a
    public-disk path for admin uploads, a full URL for imported rows), so
    nothing here has to know which kind it got.

    $count is the number of ACTIVE services inside — passed only where the
    caller has already loaded it (withCount), never fetched here, so a grid
    of tiles cannot become a per-tile query. It is ignored by the `compact`
    variant (homepage tiles show no count, matching the pattern customers
    already know). A null count renders no count line at all.
--}}

@php
    $isCompact = $variant === 'compact';
    $tint = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $category->color) ? $category->color.'26' : '#E2E8F0';
    // Admin → Homepage & sign-up → Images: never cropped/zoomed by default, optional border + gloss.
    $imgFit = \App\Support\SiteControls::imageClasses();
    $frame = \App\Support\SiteControls::frameClasses();
@endphp

@if ($isCompact)
    <a href="{{ \App\Support\Seo\PublicUrl::category($category) }}"
       {{ $attributes->merge(['class' => 'group flex h-full flex-col items-center gap-2 rounded-2xl p-1 text-center transition duration-200 hover:bg-blue-50/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600']) }}>
        <span aria-hidden="true"
              class="{{ $frame }} relative grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-2xl text-lg font-semibold text-slate-700 transition duration-200 group-hover:shadow-md sm:h-20 sm:w-20"
              style="background-color: {{ $tint }}">
            <x-customer.initial :name="$category->name" />

            @if ($category->image_url)
                <img src="{{ $category->image_url }}" alt="" loading="lazy" decoding="async"
                     width="160" height="160"
                     class="absolute inset-0 h-full w-full p-1 {{ $imgFit }}">
            @endif
        </span>

        <span class="line-clamp-2 text-xs font-medium leading-tight text-slate-900 sm:text-sm">{{ $category->name }}</span>
    </a>
@else
    <a href="{{ \App\Support\Seo\PublicUrl::category($category) }}"
       {{ $attributes->merge(['class' => 'group flex h-full min-h-28 flex-col justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-md hover:shadow-blue-900/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600']) }}>
        {{-- The lettered placeholder is always rendered, and the image (when
             there is one) sits on top of it. That is deliberate rather than an
             if/else: a stored image path that 404s — a moved file, a missing
             `storage:link`, a CDN outage — would otherwise leave an empty box on
             the customer's homepage. This way the letter is simply revealed, with
             no JavaScript and no onerror handler. --}}
        <span aria-hidden="true"
              class="{{ $frame }} relative grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-lg text-base font-semibold text-slate-700"
              style="background-color: {{ $tint }}">
            <x-customer.initial :name="$category->name" />

            @if ($category->image_url)
                <img src="{{ $category->image_url }}" alt="" loading="lazy" decoding="async"
                     width="96" height="96"
                     class="absolute inset-0 h-full w-full p-0.5 {{ $imgFit }}">
            @endif
        </span>

        <span class="flex flex-col gap-0.5">
            <span class="text-sm font-semibold text-slate-900">{{ $category->name }}</span>
            @if ($count)
                <span class="text-xs text-slate-500">{{ $count }} {{ \Illuminate\Support\Str::plural('service', $count) }}</span>
            @endif
        </span>
    </a>
@endif
