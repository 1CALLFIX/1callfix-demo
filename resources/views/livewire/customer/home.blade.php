@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $cityLabel = \App\Models\Setting::get('branding.operating_city_label', null);
@endphp

{{--
    Customer homepage (Phase C).

    Section order follows the marketplace information architecture: hero
    banner -> discovery hero (headline + location, beside the category grid)
    -> what's new -> what's most booked -> mid-page promotional strip ->
    category collections -> offers -> membership -> trust -> FAQ.

    The hero banner is the FIRST thing under the topbar: it is paid
    commercial-ad inventory (the `top` slot, sold at the premium rate), so it
    gets the position with the most attention rather than sitting a scroll
    below the fold.

    There is NO search box on this page — search lives only in the topbar
    (x-customer.header). Below the paid banner, the discovery hero is a
    headline + one supporting line + the location action on the left, and
    the category grid in its own card on the right (stacked on mobile) —
    the two-column opener every comparable home-services marketplace uses.

    EVERY section below is conditional on real data and disappears entirely
    when there is none. There is no section on this page that renders
    placeholder content, sample copy or an invented figure. See the Home
    component's docblock for the section-by-section account of what each one
    is sourced from.

    The two banner slots are independent `banners.placement` values (`top`
    and `mid`) resolved separately through Banner::scopeForSlot(). Neither
    carries content authored in this file.
--}}

<div class="mb-bottom-nav">

    {{-- ======================= Banner slot #1 (hero) =======================
         Paid `top`-slot ad inventory, placed first — directly under the
         topbar, above the discovery hero. Renders nothing when no banner is
         live, and the discovery hero below stands on its own in that case.
    --}}
    @if ($heroBanners->isNotEmpty())
        <section class="mx-auto max-w-7xl px-4 pt-3 sm:px-6 sm:pt-5 lg:px-8">
            <x-customer.banner-carousel :banners="$heroBanners"
                                        id="hero-banners"
                                        label="Featured promotions"
                                        variant="hero"
                                        :interval="config('banners.hero_rotation_ms')" />
        </section>
    @endif

    {{-- ===================== Discovery hero =====================
         A statement + the category grid, side by side on desktop and
         stacked on mobile — the layout every large home-services
         marketplace opens with. The left column carries the one <h1> and
         the location action; the right column is the category grid in its
         own card, so a customer can jump straight into a category without
         scrolling. Search is not here — it lives in the topbar
         (x-customer.header) at every width now.

         The paid `top` banner slot still renders ABOVE this (premium ad
         inventory keeps the first position); this hero is what carries the
         "what is this / where do I start" role.
    --}}
    <section class="border-b border-slate-200 bg-gradient-to-b from-blue-50/70 via-white to-white">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8">
            <div class="grid gap-8 lg:grid-cols-5 lg:items-center">

                {{-- Statement + location --}}
                <div class="lg:col-span-2">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl lg:text-4xl">
                        {{ $cityLabel ? 'Home services across '.$cityLabel : 'Home services, on call' }}
                    </h1>
                    <p class="mt-3 max-w-md text-sm text-slate-600 sm:text-base">
                        Trusted local professionals for cleaning, repairs, appliances and more —
                        priced up front and booked in a few taps.
                    </p>

                    {{-- Location as a tappable pill — opens the one header
                         location picker via a page-level event (no second
                         modal in the DOM). --}}
                    <button type="button" wire:click="$dispatch('open-location-picker')"
                            class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-300 bg-white px-4 text-sm text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        <x-icon name="map-pin" class="h-4 w-4 text-slate-500" />
                        @if ($activeZone)
                            <span>Availability for <span class="font-semibold text-slate-900">{{ $activeZone->name }}</span></span>
                        @else
                            <span>Set your area</span>
                        @endif
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-slate-400" />
                    </button>
                </div>

                {{-- Category grid. A real grid at every width now (was a
                     horizontal scroll rail under `sm`): four columns, its
                     own card, matching the homepage category block on every
                     comparable marketplace. The compact tile variant is
                     icon-over-label with no per-tile border — the card is
                     the frame. --}}
                <div class="lg:col-span-3">
                    @if ($categories->isNotEmpty())
                        <h2 id="shortcuts-heading" class="sr-only">Browse by category</h2>
                        <ul aria-labelledby="shortcuts-heading"
                            class="grid grid-cols-4 gap-1 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:gap-2 sm:p-4">
                            @foreach ($categories as $category)
                                <li>
                                    <x-customer.category-tile :category="$category" variant="compact" class="h-full" />
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <x-ui.empty-state title="No categories published yet"
                                          description="Service categories will appear here once they are published from the admin panel." />
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ========================= New & noteworthy ========================= --}}
    @if ($newServices->isNotEmpty())
        <section aria-labelledby="new-heading" class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <x-customer.section-heading id="new-heading"
                                        {{-- Plain text, not an HTML entity: the
                                             component escapes it on output, so
                                             "&amp;" here would render literally. --}}
                                        title="New & noteworthy"
                                        description="The latest additions to the catalogue."
                                        :link="route('customer.services.index', ['sort' => 'newest'])" />

            <x-customer.service-rail :cards="$newServices" :currency-symbol="$currencySymbol" labelled-by="new-heading" class="mt-6" />
        </section>
    @endif

    {{-- =========================== Most booked ===========================
         Hidden entirely when the catalogue has no booking history — the
         ranking is a real count over `bookings`, so with nothing booked
         there is nothing honest to rank. See ServiceCatalogQuery::mostBooked().
    --}}
    @if ($mostBooked->isNotEmpty())
        <section aria-labelledby="booked-heading" class="border-y border-slate-200 bg-slate-50">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <x-customer.section-heading id="booked-heading"
                                            title="Most booked services"
                                            :description="$activeZone
                                                ? 'What customers around '.$activeZone->name.' book most often.'
                                                : 'What customers book most often.'"
                                            :link="route('customer.services.index')" />

                <x-customer.service-rail :cards="$mostBooked" :currency-symbol="$currencySymbol" labelled-by="booked-heading" class="mt-6" />
            </div>
        </section>
    @endif

    {{-- ==================== Mid-page banner #1 ====================
         The `mid` slot is "between modules" (Banner::PLACEMENTS) — plural.
         Rather than pool every mid banner into one carousel at a single
         point, the homepage now DISTRIBUTES them one-per-gap down the page,
         so promotions break up the run of service rails the way every
         comparable marketplace does. Still a separate, independently-sold
         slot — never a re-render of the hero's data.

         Each gap takes the next distinct mid banner in order (no repeats).
         With a single mid banner sold, only this first gap renders — exactly
         the previous behaviour and position. Extra banners fill the gaps
         below.
    --}}
    @if ($midBanners->get(0))
        <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <x-customer.banner-carousel :banners="collect([$midBanners->get(0)])"
                                        id="mid-banner-0"
                                        label="Offers and announcements"
                                        variant="strip"
                                        :interval="config('banners.mid_rotation_ms')" />
        </section>
    @endif

    {{-- ======================= Category collections ======================= --}}
    @foreach ($collections as $collection)
        @php $collectionCategory = $collection['category']; @endphp
        @if ($collection['cards']->isNotEmpty())
            <section aria-labelledby="collection-{{ $collectionCategory->id }}"
                     @class(['mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8'])>
                <x-customer.section-heading :id="'collection-'.$collectionCategory->id"
                                            :title="$collectionCategory->name"
                                            :description="$collectionCategory->description"
                                            :link="route('customer.categories.show', $collectionCategory)" />

                <x-customer.service-rail :cards="$collection['cards']"
                                         :currency-symbol="$currencySymbol"
                                         :labelled-by="'collection-'.$collectionCategory->id"
                                         class="mt-6" />
            </section>
        @endif
    @endforeach

    {{-- Mid-page banner #2 — the next mid banner after the collections
         block. Renders only when a second mid banner has been sold. --}}
    @if ($midBanners->get(1))
        <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <x-customer.banner-carousel :banners="collect([$midBanners->get(1)])"
                                        id="mid-banner-1"
                                        label="More offers and announcements"
                                        variant="strip"
                                        :interval="config('banners.mid_rotation_ms')" />
        </section>
    @endif

    {{-- ============================== Offers ==============================
         Real, currently-active, scope-covering flash sales only. No sales,
         no section — never full-price services under an "Offers" heading.
    --}}
    @if ($offers->isNotEmpty())
        <section aria-labelledby="offers-heading" class="border-y border-slate-200 bg-slate-50">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <x-customer.section-heading id="offers-heading"
                                            title="Offers on now"
                                            description="Limited-time pricing, while it lasts."
                                            :link="route('customer.offers')" />

                <x-customer.service-rail :cards="$offers" :currency-symbol="$currencySymbol" labelled-by="offers-heading" class="mt-6" />
            </div>
        </section>
    @endif

    {{-- Mid-page banner #3 — the last of the distributed mid banners, after
         the offers rail. Renders only when a third mid banner has been sold;
         any further mid banners beyond this are not shown (three interruptions
         down one page is already the ceiling before it reads as clutter). --}}
    @if ($midBanners->get(2))
        <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <x-customer.banner-carousel :banners="collect([$midBanners->get(2)])"
                                        id="mid-banner-2"
                                        label="Announcements"
                                        variant="strip"
                                        :interval="config('banners.mid_rotation_ms')" />
        </section>
    @endif

    {{-- =========================== Membership ============================
         Real, active `customer_membership` plans. Buying one is Phase E, so
         this states what the plan is and links to the honest placeholder
         rather than implying checkout works here.
    --}}
    @if ($membershipPlans->isNotEmpty())
        <section aria-labelledby="membership-heading" class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-700 via-blue-600 to-blue-800 p-6 shadow-xl shadow-blue-900/20 sm:p-10">
                {{-- Soft light bloom, purely decorative. --}}
                <div aria-hidden="true" class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>

                <div class="relative">
                    <h2 id="membership-heading" class="text-xl font-bold tracking-tight text-white sm:text-2xl">
                        {{ $platformName }} membership
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm text-blue-100">
                        Membership plans available on your account.
                    </p>

                    <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($membershipPlans as $plan)
                            <li class="rounded-2xl bg-white/10 p-5 ring-1 ring-inset ring-white/15 backdrop-blur-sm">
                                <h3 class="text-sm font-semibold text-white">{{ $plan->name }}</h3>
                                <p class="mt-2 text-2xl font-bold text-white">
                                    {{ $currencySymbol }}{{ number_format((float) $plan->price, 2) }}
                                    <span class="text-xs font-medium text-blue-200">/ {{ $plan->billing_cycle }}</span>
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('customer.coming-soon', 'booking') }}"
                       class="mt-6 inline-flex min-h-11 items-center rounded-lg bg-white px-5 text-sm font-semibold text-blue-700 shadow-sm transition hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        About membership
                    </a>
                </div>
            </div>
        </section>
    @endif

    {{-- ===================== Trust / service quality =====================
         Deliberately qualitative. No counts, ratings or "10,000+ happy
         customers" figures appear anywhere on this page — there is no
         verified source for such a number and inventing one would be
         fabricated marketing data. Every claim below describes a mechanism
         this system actually implements.
    --}}
    <section aria-labelledby="trust-heading" class="border-y border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <h2 id="trust-heading" class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                Why choose {{ $platformName }}
            </h2>
            <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['shield', 'Verified professionals', 'Every professional is onboarded through document verification before taking work.'],
                    ['banknotes', 'Transparent pricing', 'Prices are set by us, shown before you book, and calculated on our servers — never guessed.'],
                    ['clock', 'One-time code security', 'Jobs start and finish only when you share your one-time code with the professional.'],
                    ['chat', 'Support when you need it', 'Reach a real person through the help centre if something is not right.'],
                ] as [$icon, $trustTitle, $trustBody])
                    <li class="rounded-2xl border border-slate-200 p-5 transition hover:border-blue-200 hover:shadow-md hover:shadow-blue-900/5">
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
                            <x-icon :name="$icon" class="h-5 w-5" />
                        </span>
                        <h3 class="mt-3 text-sm font-semibold text-slate-900">{{ $trustTitle }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $trustBody }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ============================== FAQ ============================== --}}
    @if ($faqs->isNotEmpty())
        <section aria-labelledby="faq-heading" class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            <h2 id="faq-heading" class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                Frequently asked questions
            </h2>
            <div class="mt-8 divide-y divide-slate-200 border-y border-slate-200">
                @foreach ($faqs as $faq)
                    {{-- Native <details>: keyboard-operable and screen-reader
                         announced with zero JavaScript. --}}
                    <details class="group py-4">
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 rounded text-left text-base font-medium text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            <span>{{ $faq->question }}</span>
                            <span aria-hidden="true" class="shrink-0 text-slate-400 transition group-open:rotate-180">
                                <x-icon name="chevron-down" class="h-4 w-4" />
                            </span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $faq->answer }}</p>
                    </details>
                @endforeach
            </div>
            <p class="mt-6 text-sm text-slate-600">
                Still stuck?
                <a href="{{ route('customer.help') }}"
                   class="rounded font-semibold text-blue-700 underline underline-offset-4 transition hover:text-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    Visit the help centre
                </a>.
            </p>
        </section>
    @endif
</div>
