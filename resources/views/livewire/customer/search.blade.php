{{--
    The search screen (Phase C). See the Search component's docblock for why
    this shares its query layer with the REST API and why recent searches live
    in the session rather than on the user record.
--}}

<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Search</h1>

        {{-- The screen's own input. Deliberately a plain field bound to $query
             rather than the header's suggestion dropdown: results appear below
             in full, so a floating list on top of them would just be in the
             way. --}}
        <div class="relative mt-4 max-w-xl">
            <label for="search-page-input" class="sr-only">Search for a service</label>
            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center">
                <x-icon name="magnifying-glass" class="h-5 w-5 text-slate-400" />
            </span>
            <input id="search-page-input" type="search"
                   wire:model.live.debounce.300ms="query"
                   autofocus
                   placeholder="Try “AC service”, “plumber”, “deep cleaning”"
                   class="customer-search block min-h-12 w-full rounded-lg border border-slate-300 bg-white py-3 pl-11 pr-10 text-base text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600">
            @if ($query !== '')
                <button type="button" wire:click="clear"
                        class="absolute inset-y-0 right-0 grid w-10 place-items-center text-slate-400 transition hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                    <span class="sr-only">Clear search</span>
                    <x-icon name="x-circle" class="h-5 w-5" />
                </button>
            @endif
        </div>

        {{-- Loading and result-count are announced politely: the results
             below change without focus moving, so a screen-reader user has to
             be told (WCAG 2.1 AA 4.1.3). --}}
        <p role="status" aria-live="polite" class="mt-3 text-sm text-slate-600">
            <span wire:loading wire:target="query">Searching…</span>
            <span wire:loading.remove wire:target="query">
                @if ($hasQuery)
                    {{ $services->count() }} {{ \Illuminate\Support\Str::plural('service', $services->count()) }} for “{{ $term }}”
                @elseif ($term !== '')
                    Type at least {{ $minLength }} characters to search.
                @endif
            </span>
        </p>

        {{-- ======================= Pre-search state ======================= --}}
        @unless ($hasQuery)
            @if ($recent->isNotEmpty())
                <section aria-labelledby="recent-heading" class="mt-8">
                    <div class="flex items-center justify-between gap-4">
                        <h2 id="recent-heading" class="text-sm font-semibold text-slate-900">Recent searches</h2>
                        <button type="button" wire:click="clearRecent"
                                class="inline-flex min-h-11 items-center rounded text-xs font-medium text-slate-500 underline-offset-4 hover:text-slate-900 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            Clear
                        </button>
                    </div>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($recent as $recentTerm)
                            <li>
                                <button type="button" wire:click="useRecent(@js($recentTerm))"
                                        class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-300 bg-white px-4 text-sm text-slate-700 transition hover:border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                                    <x-icon name="clock" class="h-3.5 w-3.5 text-slate-400" />
                                    {{ $recentTerm }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($suggestions->isNotEmpty())
                <section aria-labelledby="browse-heading" class="mt-8">
                    {{-- Real category names, not an invented "popular searches"
                         list: no search-volume data exists anywhere in this
                         application to derive one from honestly. --}}
                    <h2 id="browse-heading" class="text-sm font-semibold text-slate-900">Browse by category</h2>
                    <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($suggestions as $category)
                            <li><x-customer.category-tile :category="$category" class="h-full" /></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endunless

        {{-- ========================= Result groups ========================= --}}
        @if ($hasQuery)
            @if ($categories->isNotEmpty() || $subcategories->isNotEmpty())
                <section aria-labelledby="matched-categories-heading" class="mt-8">
                    <h2 id="matched-categories-heading" class="text-sm font-semibold text-slate-900">Matching categories</h2>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($categories as $category)
                            <li>
                                <a href="{{ \App\Support\Seo\PublicUrl::category($category) }}"
                                   class="inline-flex min-h-11 items-center rounded-full border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 transition hover:border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                                    {{ $category->name }}
                                </a>
                            </li>
                        @endforeach
                        @foreach ($subcategories as $sub)
                            <li>
                                {{-- A subcategory links to its parent category
                                     already filtered to it — the same URL the
                                     category page's own chips produce, so the
                                     two routes into that view are identical. --}}
                                <a href="{{ $sub->category ? \App\Support\Seo\PublicUrl::category($sub->category, null, ['sub' => $sub->id]) : route('customer.categories.index') }}"
                                   class="inline-flex min-h-11 items-center rounded-full border border-slate-200 bg-slate-50 px-4 text-sm text-slate-700 transition hover:border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                                    {{ $sub->name }}
                                    @if ($sub->category)
                                        <span class="ml-1.5 text-xs text-slate-500">in {{ $sub->category->name }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($services->isNotEmpty())
                <section aria-labelledby="matched-services-heading" class="mt-8">
                    <h2 id="matched-services-heading" class="text-sm font-semibold text-slate-900">Services</h2>
                    <ul class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($services as $card)
                            <li><x-customer.service-card :card="$card" :currency-symbol="$currencySymbol" /></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- One search (owner 2026-10-30): shop items and shops, where the shop is on in this city. --}}
            @if ($products->isNotEmpty())
                <section aria-labelledby="matched-products-heading" class="mt-8" data-testid="search-page-products">
                    <h2 id="matched-products-heading" class="text-sm font-semibold text-slate-900">{{ \App\Support\SiteText::get('search.products') }}</h2>
                    <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach ($products as $product)
                            <li>
                                <a href="{{ route('customer.shop.product', [$shopCity, $product->store->slug, $product->slug]) }}" wire:navigate
                                   class="block h-full overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:border-blue-300 hover:shadow-md">
                                    <span class="block aspect-square bg-slate-50">
                                        @if ($img = $product->image_urls[0] ?? null)<img src="{{ $img }}" alt="" loading="lazy" class="h-full w-full object-cover">@endif
                                    </span>
                                    <span class="block p-2.5">
                                        <span class="line-clamp-2 text-sm font-semibold text-slate-900">{{ $product->name }}</span>
                                        <span class="mt-0.5 block text-sm font-bold text-slate-900">{{ $currencySymbol }}{{ number_format((float) $product->effective_price, 2) }}</span>
                                        <span class="block truncate text-xs text-slate-500">{{ $product->store->name }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($shops->isNotEmpty())
                <section aria-labelledby="matched-shops-heading" class="mt-8" data-testid="search-page-shops">
                    <h2 id="matched-shops-heading" class="text-sm font-semibold text-slate-900">{{ \App\Support\SiteText::get('search.shops') }}</h2>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($shops as $shop)
                            <li><a href="{{ route('customer.shop.store', [$shopCity, $shop->slug]) }}" wire:navigate
                                   class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-300 px-4 text-sm font-medium text-slate-800 hover:bg-slate-50">
                                    {{ $shop->name }}
                                    <span class="text-xs text-slate-500">· {{ $shop->is_open ? \App\Support\SiteText::get('search.open_now') : \App\Support\SiteText::get('cart.closed_now') }}</span>
                                </a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($services->isEmpty() && $categories->isEmpty() && $subcategories->isEmpty() && $products->isEmpty() && $shops->isEmpty())
                {{-- Genuine no-results: nothing matched in any group. --}}
                <div class="mt-8 rounded-xl border border-slate-200">
                    <x-ui.empty-state icon="magnifying-glass"
                                      title="Nothing matched “{{ $term }}”"
                                      description="Try a shorter or more general word — “clean” rather than “deep cleaning service”." />
                    <div class="pb-8 text-center">
                        <a href="{{ route('customer.categories.index') }}"
                           class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            Browse all categories
                        </a>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
