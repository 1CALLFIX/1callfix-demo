@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $cityLabel = \App\Models\Setting::get('branding.operating_city_label', null);
@endphp

{{--
    Customer homepage (Phase C).

    Section order follows the marketplace information architecture: hero
    banner -> discovery hero (headline, beside the category grid) -> what's
    new -> what's most booked -> mid-page promotional strip -> category
    collections -> offers -> membership -> trust -> FAQ.

    The hero banner is the FIRST thing under the topbar: it is paid
    commercial-ad inventory (the `top` slot, sold at the premium rate), so it
    gets the position with the most attention rather than sitting a scroll
    below the fold.

    There is NO search box on this page — search lives only in the topbar
    (x-customer.header). Below the paid banner, the discovery hero is a
    headline + one supporting line on the left, and the category grid in its
    own card on the right (stacked on mobile) — the two-column opener every
    comparable home-services marketplace uses. Location is set from the one
    control in the header (x-customer.header); there is no second location
    control on this page.

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

    @php
        $controls = \App\Support\SiteControls::class;
        $t = fn (string $key, array $replace = []) => \App\Support\SiteText::get($key, $replace);
    @endphp

    {{-- Sections in the Super Admin's order, each on/off (Admin → Growth → Homepage sections, App\Support\HomeLayout).
         Each section still renders nothing without real data. --}}
    @unless (in_array('categories', $sections, true))
        {{-- The page keeps its one <h1> when the category icons are switched off. --}}
        <h1 class="sr-only">{{ $controls::headline() }}</h1>
    @endunless
    @foreach ($sections as $section)
    @switch($section)
    @case('hero_banner')
        {{-- Paid `top`-slot banner. Renders nothing when no banner is live. --}}
        @include('livewire.customer.partials.home-hero-banner')
        @break

    @case('module_cards')
        @if ($cards)
            @include('livewire.customer.partials.home-cards')
        @endif
        @break

    @case('doors')
        @if ($doors)
            @include('livewire.customer.partials.home-doors')
        @endif
        @break

    @case('parcel_card')
        {{-- Only where parcel is switched on (Admin → Modules) for the zone being browsed. --}}
        @if ($parcelActive)
            @include('livewire.customer.partials.home-parcel-card')
        @endif
        @break

    @case('categories')
    {{-- ===================== Discovery opener =====================
         REF 1CF-HOME-CATEGORY-HERO-001 (owner brief 2026-10-15): a clear
         headline over the category icons in even rows — the opener every
         large home-services marketplace uses. Columns, how many categories,
         the headline and whether the photo tiles show beside the icons are
         all Super Admin settings (App\Support\SiteControls); the photo
         tiles are OFF by default because they made the first screen read as
         clutter. Search and location stay in the topbar.
    --}}
    <section class="relative border-b border-slate-200 bg-gradient-to-b from-blue-50 via-white to-white">
        <div class="relative mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-10 lg:px-8">
            @php
                // Home Spotlight tiles (admin-curated, auto-topped-up) — see Home::spotlight().
                $collage = $spotlight;
                $showCollage = $controls::showPhotoTiles() && $collage->count() >= 2;
                $collageCols = $collage->count() >= 6 ? 3 : 2;
                $colsMobile = $controls::columnsMobile();
                // Beside the photo tiles the grid only has half the width.
                $colsDesktop = $showCollage ? min(4, $controls::columnsDesktop()) : $controls::columnsDesktop();
                $headline = $controls::headline();
            @endphp

            <h1 @class([
                    'text-2xl font-bold tracking-tight text-slate-900 sm:text-4xl',
                    'sr-only' => ! $controls::showHeadline(),
                ])>{{ $headline }}</h1>

            <div @class(['grid gap-8', 'mt-5 sm:mt-7' => $controls::showHeadline(), 'lg:grid-cols-2 lg:items-start' => $showCollage])>

                {{-- Category icons, in rows. Columns per Admin setting. --}}
                <div>
                    @if ($categories->isNotEmpty())
                        <h2 id="shortcuts-heading" class="sr-only">{{ $t('home.categories.sr') }}</h2>
                        <ul aria-labelledby="shortcuts-heading"
                            class="cf-cat-grid grid gap-x-2 gap-y-4 rounded-3xl border border-slate-200 bg-white p-3 shadow-lg shadow-blue-900/5 sm:gap-x-4 sm:gap-y-6 sm:p-6"
                            style="--cf-cols-m: {{ $colsMobile }}; --cf-cols-d: {{ $colsDesktop }};">
                            {{-- Parcel as the first icon, where parcel is on (Admin switch, picture uploadable). --}}
                            @if ($parcelActive && $controls::parcelGridTile())
                                <li>
                                    <a href="{{ route('customer.parcels.create') }}" wire:navigate
                                       class="group flex h-full flex-col items-center gap-2 rounded-2xl p-1 text-center transition duration-200 hover:bg-blue-50/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                        <span aria-hidden="true"
                                              class="{{ $controls::frameClasses() }} relative grid {{ $controls::iconBoxClasses() }} shrink-0 place-items-center overflow-hidden rounded-2xl bg-blue-100 transition duration-200 group-hover:shadow-md">
                                            <img src="{{ $controls::parcelIconUrl() }}" alt="" loading="lazy" decoding="async" width="160" height="160"
                                                 class="absolute inset-0 h-full w-full p-1 {{ $controls::imageClasses() }}">
                                            @if ($controls::parcelNewBadge())
                                                <span class="absolute right-0.5 top-0.5 rounded-full bg-amber-400 px-1.5 text-[10px] font-bold leading-4 text-slate-900">{{ $controls::parcelBadgeText() }}</span>
                                            @endif
                                        </span>
                                        <span class="line-clamp-2 text-xs font-medium leading-tight text-slate-900 sm:text-sm">{{ $controls::parcelTitle() }}</span>
                                    </a>
                                </li>
                            @endif
                            @foreach ($categories as $category)
                                <li>
                                    <x-customer.category-tile :category="$category" variant="compact" class="h-full" />
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <x-ui.empty-state :title="$t('home.categories.empty_title')"
                                          :description="$t('home.categories.empty_line')" />
                    @endif
                </div>

                {{-- Optional photo tiles (Admin switch, default off), from `md` up. --}}
                @if ($showCollage)
                    <div class="hidden gap-3 md:grid" aria-label="Featured services"
                         style="grid-template-columns:repeat({{ $collageCols }},minmax(0,1fr));grid-template-rows:repeat({{ (int) ceil($collage->count() / $collageCols) }},minmax(0,1fr));min-height:16rem">
                        @foreach ($collage as $tile)
                            <a href="{{ $tile['url'] }}"
                               class="group relative block h-full overflow-hidden rounded-3xl bg-white shadow-lg shadow-slate-900/10 transition duration-300 hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                @if ($tile['image_url'])
                                    <span class="{{ $controls::frameClasses() }} absolute inset-0 overflow-hidden rounded-3xl">
                                        <img src="{{ $tile['image_url'] }}" alt="{{ $tile['name'] }}" loading="lazy" decoding="async"
                                             class="h-full w-full {{ $controls::imageClasses() }}">
                                    </span>
                                @endif
                                @if ($tile['badge'])
                                    <span class="absolute rounded-full bg-gradient-to-r from-rose-500 to-orange-400 px-2.5 py-0.5 text-xs font-bold text-white shadow-md"
                                          style="left:.5rem;top:.5rem">{{ $tile['badge'] }}</span>
                                @endif
                                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent px-4 pb-3 pt-10 text-sm font-bold text-white">{{ $tile['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>
        @break

    {{-- ========================= New & noteworthy ========================= --}}
    @case('new')
    @if ($newServices->isNotEmpty())
        <section aria-labelledby="new-heading" class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <x-customer.section-heading id="new-heading"
                                        :title="$t('home.new.title')"
                                        :description="$t('home.new.line')"
                                        :link-label="$t('home.see_all')"
                                        :link="route('customer.services.index', ['sort' => 'newest'])" />

            <x-customer.service-rail :cards="$newServices" :currency-symbol="$currencySymbol" labelled-by="new-heading" class="mt-6" />
        </section>
    @endif
        @break

    {{-- =========================== Most booked ===========================
         Hidden entirely when the catalogue has no booking history — the
         ranking is a real count over `bookings`, so with nothing booked
         there is nothing honest to rank. See ServiceCatalogQuery::mostBooked().
    --}}
    @case('most_booked')
    @if ($mostBooked->isNotEmpty())
        <section aria-labelledby="booked-heading" class="border-y border-slate-200 bg-slate-50">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <x-customer.section-heading id="booked-heading"
                                            :title="$t('home.booked.title')"
                                            :description="$activeZone
                                                ? $t('home.booked.line_area', ['area' => $activeZone->name])
                                                : $t('home.booked.line')"
                                            :link-label="$t('home.see_all')"
                                            :link="route('customer.services.index')" />

                <x-customer.service-rail :cards="$mostBooked" :currency-symbol="$currencySymbol" labelled-by="booked-heading" class="mt-6" />
            </div>
        </section>
    @endif
        @break

    {{-- ==================== Mid-page banners ====================
         The `mid` slot is "between modules" (Banner::PLACEMENTS) — plural.
         Rather than pool every mid banner into one carousel at a single
         point, the homepage now DISTRIBUTES them one-per-gap down the page,
         so promotions break up the run of service rails the way every
         comparable marketplace does. Still a separate, independently-sold
         slot — never a re-render of the hero's data.

         Each gap takes the next distinct mid banner in order (no repeats).
         With a single mid banner sold, only this first gap renders — exactly
         the previous behaviour and position. Extra banners fill the gaps
         below. Each gap is its own section, placed by the Super Admin.
    --}}
    @case('mid_banner_1')
    @case('mid_banner_2')
    @case('mid_banner_3')
    @php $midIndex = (int) substr($section, -1) - 1; @endphp
    @if ($midBanners->get($midIndex))
        <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <x-customer.banner-carousel :banners="collect([$midBanners->get($midIndex)])"
                                        :id="'mid-banner-'.$midIndex"
                                        :label="$t('home.banner.mid')"
                                        variant="strip"
                                        :interval="config('banners.mid_rotation_ms')" />
        </section>
    @endif
        @break

    {{-- ======================= Category collections ======================= --}}
    @case('collections')
    @foreach ($collections as $collection)
        @php $collectionCategory = $collection['category']; @endphp
        @if ($collection['cards']->isNotEmpty())
            <section aria-labelledby="collection-{{ $collectionCategory->id }}"
                     @class(['mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8'])>
                <x-customer.section-heading :id="'collection-'.$collectionCategory->id"
                                            :title="$collectionCategory->name"
                                            :description="$collectionCategory->description"
                                            :link-label="$t('home.see_all')"
                                            :link="\App\Support\Seo\PublicUrl::category($collectionCategory)" />

                <x-customer.service-rail :cards="$collection['cards']"
                                         :currency-symbol="$currencySymbol"
                                         :labelled-by="'collection-'.$collectionCategory->id"
                                         class="mt-6" />
            </section>
        @endif
    @endforeach

        @break

    {{-- ============================== Offers ==============================
         Real, currently-active, scope-covering flash sales only. No sales,
         no section — never full-price services under an "Offers" heading.
    --}}
    @case('offers')
    @if ($offers->isNotEmpty())
        <section aria-labelledby="offers-heading" class="border-y border-slate-200 bg-slate-50">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <x-customer.section-heading id="offers-heading"
                                            :title="$t('home.offers.title')"
                                            :description="$t('home.offers.line')"
                                            :link-label="$t('home.see_all')"
                                            :link="route('customer.offers')" />

                <x-customer.service-rail :cards="$offers" :currency-symbol="$currencySymbol" labelled-by="offers-heading" class="mt-6" />
            </div>
        </section>
    @endif

        @break

    {{-- =========================== Membership ============================
         Real, active `customer_membership` plans, read from the Plan Engine.
         "About membership" opens the plan's real details + purchase page.
    --}}
    @case('membership')
    @if ($membershipPlans->isNotEmpty())
        <section aria-labelledby="membership-heading" class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-700 via-blue-600 to-blue-800 p-6 shadow-xl shadow-blue-900/20 sm:p-10">
                {{-- Soft light bloom, purely decorative. --}}
                <div aria-hidden="true" class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>

                <div class="relative">
                    <h2 id="membership-heading" class="text-xl font-bold tracking-tight text-white sm:text-2xl">
                        {{ $t('home.membership.title', ['brand' => $platformName]) }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm text-blue-100">
                        {{ $t('home.membership.line') }}
                    </p>

                    <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($membershipPlans as $plan)
                            <li class="rounded-2xl bg-white/10 p-5 ring-1 ring-inset ring-white/15 backdrop-blur-sm">
                                <h3 class="text-sm font-semibold text-white">{{ $plan->name }}</h3>
                                <p class="mt-2 text-2xl font-bold text-white">
                                    {{ $currencySymbol }}{{ number_format((float) $plan->price, 2) }}
                                    <span class="text-xs font-medium text-blue-200">/ {{ $plan->validityLabel() }}</span>
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('customer.membership.show', $membershipPlans->first()) }}"
                       class="mt-6 inline-flex min-h-11 items-center rounded-lg bg-white px-5 text-sm font-semibold text-blue-700 shadow-sm transition hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        {{ $t('home.membership.button') }}
                    </a>
                </div>
            </div>
        </section>
    @endif
        @break

    {{-- Real Google Business reviews (admin-controlled; renders nothing without fresh data). --}}
    @case('reviews')
    <x-customer.google-reviews />
        @break

    {{-- ===================== Trust / service quality =====================
         Deliberately qualitative. No counts, ratings or "10,000+ happy
         customers" figures appear anywhere on this page — there is no
         verified source for such a number and inventing one would be
         fabricated marketing data. Every claim below describes a mechanism
         this system actually implements.
    --}}
    @case('trust')
    <section aria-labelledby="trust-heading" class="border-y border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <h2 id="trust-heading" class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                {{ $t('home.trust.title', ['brand' => $platformName]) }}
            </h2>
            <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['shield', 'banknotes', 'clock', 'chat'] as $n => $icon)
                    @php [$trustTitle, $trustBody] = [$t('home.trust.'.($n + 1).'.title'), $t('home.trust.'.($n + 1).'.body')]; @endphp
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
        @break

    {{-- ============================== FAQ ============================== --}}
    @case('faq')
    @if ($faqs->isNotEmpty())
        <section aria-labelledby="faq-heading" class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            <h2 id="faq-heading" class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                {{ $t('home.faq.title') }}
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
                {{ $t('home.faq.still') }}
                <a href="{{ route('customer.help') }}"
                   class="rounded font-semibold text-blue-700 underline underline-offset-4 transition hover:text-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    {{ $t('home.faq.help') }}
                </a>.
            </p>
        </section>
    @endif
        @break
    @endswitch
    @endforeach
</div>
