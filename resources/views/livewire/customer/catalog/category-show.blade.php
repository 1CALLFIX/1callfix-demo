{{--
    One category's services (Phase C). See the CategoryShow component's
    docblock for why every filter is applied in the database rather than in
    the browser.

    Result-count changes are announced in a polite live region, because
    filtering by subcategory or typing in the in-category search changes the
    grid without moving focus — a sighted user sees it, a screen-reader user
    would otherwise not be told (WCAG 2.1 AA 4.1.3).
--}}

<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => 'Home', 'url' => route('customer.home')],
            ['label' => 'Categories', 'url' => route('customer.categories.index')],
            ['label' => $category->name, 'url' => null],
        ]" />

        {{-- ============================ Header ============================ --}}
        <header class="mt-4 flex flex-col gap-6 sm:flex-row sm:items-center">
            @if ($category->image_url)
                <img src="{{ $category->image_url }}" alt="" loading="lazy" decoding="async"
                     width="112" height="112"
                     class="h-20 w-20 shrink-0 rounded-2xl object-cover sm:h-28 sm:w-28">
            @endif

            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $category->name }}</h1>
                @if ($category->description)
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600">{{ $category->description }}</p>
                @endif
            </div>
        </header>

        {{-- ========================= Subcategories =========================
             A rail of filter chips, not links: staying on this screen keeps
             the customer's search term and sort intact, and the URL still
             updates (#[Url] on $subcategory) so the filtered view is
             shareable and back-button-able.
        --}}
        @if ($subcategories->isNotEmpty())
            <nav aria-label="Subcategories" class="mt-8">
                <ul class="flex gap-2 overflow-x-auto pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <li>
                        <button type="button" wire:click="selectSubcategory(null)"
                                aria-pressed="{{ $subcategory === null ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex min-h-11 shrink-0 items-center whitespace-nowrap rounded-full border px-4 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
                                    'border-slate-900 bg-slate-900 text-white' => $subcategory === null,
                                    'border-slate-300 bg-white text-slate-700 hover:border-slate-400' => $subcategory !== null,
                                ])>All</button>
                    </li>
                    @foreach ($subcategories as $sub)
                        <li>
                            <button type="button" wire:click="selectSubcategory({{ $sub->id }})"
                                    aria-pressed="{{ $subcategory === $sub->id ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex min-h-11 shrink-0 items-center whitespace-nowrap rounded-full border px-4 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
                                        'border-slate-900 bg-slate-900 text-white' => $subcategory === $sub->id,
                                        'border-slate-300 bg-white text-slate-700 hover:border-slate-400' => $subcategory !== $sub->id,
                                    ])>{{ $sub->name }}</button>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        {{-- ====================== Search within + sort ====================== --}}
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative sm:max-w-xs sm:flex-1">
                <label for="category-search" class="sr-only">Search within {{ $category->name }}</label>
                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center">
                    <x-icon name="magnifying-glass" class="h-4 w-4 text-slate-400" />
                </span>
                <input id="category-search" type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Search in {{ $category->name }}"
                       class="customer-search block min-h-11 w-full rounded-lg border border-slate-300 bg-white py-2 pl-10 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-slate-900 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900">
            </div>

            <div class="flex items-center gap-3">
                <label for="category-sort" class="text-sm text-slate-600">Sort</label>
                <select id="category-sort" wire:model.live="sort"
                        class="min-h-11 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900">
                    @foreach (\App\Livewire\Customer\Catalog\CategoryShow::SORTS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- ============================ Results ============================ --}}
        <p role="status" aria-live="polite" class="mt-4 text-sm text-slate-600">
            <span wire:loading.remove wire:target="search,sort,subcategory,selectSubcategory,gotoPage,nextPage,previousPage">
                {{ $paginator->total() }} {{ \Illuminate\Support\Str::plural('service', $paginator->total()) }}
                @if ($hasFilters) matching your filters @endif
            </span>
            <span wire:loading wire:target="search,sort,subcategory,selectSubcategory,gotoPage,nextPage,previousPage">Loading…</span>
        </p>

        @if ($cards->isNotEmpty())
            {{-- Visually hidden <h2> so the page outline runs h1 -> h2 -> the
                 cards' own h3s, rather than jumping a level. See the same
                 note in service-index.blade.php. --}}
            <h2 id="results-heading" class="sr-only">Services in {{ $category->name }}</h2>
            <ul aria-labelledby="results-heading" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($cards as $card)
                    <li><x-customer.service-card :card="$card" :currency-symbol="$currencySymbol" /></li>
                @endforeach
            </ul>

            <div class="mt-8">{{ $paginator->onEachSide(1)->links() }}</div>
        @else
            <div class="mt-4 rounded-xl border border-slate-200">
                @if ($hasFilters)
                    <x-ui.empty-state icon="magnifying-glass"
                                      title="Nothing matched those filters"
                                      description="Try a different subcategory or a shorter search term." />
                    <div class="pb-8 text-center">
                        <button type="button" wire:click="clearFilters"
                                class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            Clear filters
                        </button>
                    </div>
                @else
                    <x-ui.empty-state title="No services in this category yet"
                                      description="Services appear here once they are published from the admin panel." />
                @endif
            </div>
        @endif

        {{-- A `mid`-slot banner targeted at THIS category, if one is sold. --}}
        @if ($banners->isNotEmpty())
            <div class="mt-12">
                <x-customer.banner-carousel :banners="$banners"
                                            id="category-banners"
                                            label="Offers and announcements"
                                            variant="strip"
                                            :interval="config('banners.mid_rotation_ms')" />
            </div>
        @endif
    </div>
</div>
