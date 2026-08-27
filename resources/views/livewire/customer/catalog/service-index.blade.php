{{--
    The whole catalog, or just what is on offer (Phase C). One template for
    both because they are the same screen with a different narrowing — see
    the ServiceIndex component's docblock.
--}}

<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => 'Home', 'url' => route('customer.home')],
            ['label' => $offersOnly ? 'Offers' : 'All services', 'url' => null],
        ]" />

        <header class="mt-4">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                {{ $offersOnly ? 'Offers on now' : 'All services' }}
            </h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                {{ $offersOnly
                    ? 'Services with limited-time pricing available in your area right now.'
                    : 'Everything we can send a verified professional out for.' }}
            </p>
        </header>

        {{-- ============================ Filters ============================ --}}
        <div class="mt-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="relative lg:max-w-xs lg:flex-1">
                <label for="service-search" class="sr-only">Search services</label>
                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center">
                    <x-icon name="magnifying-glass" class="h-4 w-4 text-slate-400" />
                </span>
                <input id="service-search" type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Search services"
                       class="customer-search block min-h-11 w-full rounded-lg border border-slate-300 bg-white py-2 pl-10 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-slate-900 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900">
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <label for="service-category" class="text-sm text-slate-600">Category</label>
                <select id="service-category" wire:model.live="category"
                        class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900">
                    <option value="">All categories</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>

                <label for="service-sort" class="text-sm text-slate-600">Sort</label>
                <select id="service-sort" wire:model.live="sort"
                        class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900">
                    @foreach (\App\Livewire\Customer\Catalog\CategoryShow::SORTS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- ============================ Results ============================ --}}
        <p role="status" aria-live="polite" class="mt-4 text-sm text-slate-600">
            <span wire:loading.remove wire:target="search,sort,category,gotoPage,nextPage,previousPage">
                {{ $paginator->total() }} {{ \Illuminate\Support\Str::plural('service', $paginator->total()) }}
                @if ($hasFilters) matching your filters @endif
            </span>
            <span wire:loading wire:target="search,sort,category,gotoPage,nextPage,previousPage">Loading…</span>
        </p>

        @if ($cards->isNotEmpty())
            {{-- Each card's title is an <h3>. Without this <h2> the document
                 jumped straight from the page <h1> to those <h3>s, which
                 breaks the outline a screen-reader user navigates by (WCAG
                 2.1 AA 1.3.1). It is visually hidden because the page <h1>
                 already says "All services" / "Offers on now" — a second
                 visible heading saying the same thing would be noise. Caught
                 by a heading-order probe in browser testing; every other
                 catalog screen already had a real <h2> above its cards. --}}
            <h2 id="results-heading" class="sr-only">Services</h2>
            <ul aria-labelledby="results-heading" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($cards as $card)
                    <li><x-customer.service-card :card="$card" :currency-symbol="$currencySymbol" /></li>
                @endforeach
            </ul>

            <div class="mt-8">{{ $paginator->onEachSide(1)->links() }}</div>
        @else
            <div class="mt-4 rounded-xl border border-slate-200">
                @if ($offersOnly && ! $hasFilters)
                    {{-- No live flash sale for this viewer. Stated plainly
                         rather than backfilled with full-price services. --}}
                    <x-ui.empty-state icon="tag"
                                      title="No offers running right now"
                                      description="Limited-time pricing appears here whenever a sale is live in your area." />
                    <div class="pb-8 text-center">
                        <a href="{{ route('customer.services.index') }}"
                           class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            Browse all services
                        </a>
                    </div>
                @elseif ($hasFilters)
                    <x-ui.empty-state icon="magnifying-glass"
                                      title="Nothing matched those filters"
                                      description="Try a different category or a shorter search term." />
                    <div class="pb-8 text-center">
                        <button type="button" wire:click="clearFilters"
                                class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            Clear filters
                        </button>
                    </div>
                @else
                    <x-ui.empty-state title="No services published yet"
                                      description="Services appear here once they are published from the admin panel." />
                @endif
            </div>
        @endif

        @if ($banners->isNotEmpty())
            <div class="mt-12">
                <x-customer.banner-carousel :banners="$banners"
                                            id="service-index-banners"
                                            label="Offers and announcements"
                                            variant="strip" />
            </div>
        @endif
    </div>
</div>
