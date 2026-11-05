@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => $t('shop.crumb_home'), 'url' => route('customer.home')],
            ['label' => $city->name, 'url' => route('customer.city.show', $city->slug)],
            ['label' => $t('shop.crumb_shop'), 'url' => null],
        ]" />

        {{-- Our own opening: the city's shops, not a faceless catalogue. --}}
        <header class="mt-4 rounded-3xl bg-gradient-to-br from-blue-600 to-blue-800 px-5 py-6 text-white sm:px-8 sm:py-8">
            <p class="text-xs font-semibold uppercase tracking-widest text-blue-100">{{ $t('shop.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('shop.home.title') }}</h1>
            <p class="mt-1 max-w-xl text-sm text-blue-100">{{ $t('shop.home.intro') }}</p>
            <label class="mt-4 block max-w-xl">
                <span class="sr-only">{{ $t('shop.home.search_label', ['city' => $city->name]) }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $t('shop.home.search_placeholder') }}"
                       class="block min-h-12 w-full rounded-xl border-0 bg-white px-4 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:outline focus:outline-2 focus:outline-white">
            </label>
        </header>

        @if ($cartMessage)
            <p role="status" class="mt-4 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $cartMessage }}</p>
        @endif

        @if ($categories->isNotEmpty())
            <nav aria-label="{{ $t('shop.home.categories') }}" class="mt-5 flex gap-2 overflow-x-auto pb-1">
                @foreach ($categories as $c)
                    <button type="button" wire:click="pickCategory('{{ $c->slug }}')"
                            @class(['shrink-0 rounded-full border px-4 py-2 text-sm font-medium',
                                    'border-blue-600 bg-blue-600 text-white' => $parentCategory?->id === $c->id,
                                    'border-slate-300 bg-white text-slate-700 hover:border-blue-400' => $parentCategory?->id !== $c->id])>
                        {{ $c->name }}
                    </button>
                @endforeach
            </nav>
            {{-- Sub-categories of the picked category (2026-11-05). Tap the picked one again to see the whole category. --}}
            @if ($subCategories->isNotEmpty())
                <nav aria-label="{{ $parentCategory->name }}" class="mt-2 flex gap-2 overflow-x-auto pb-1" data-testid="shop-subcategories">
                    @foreach ($subCategories as $sub)
                        <button type="button" wire:click="pickSubCategory('{{ $sub->slug }}')"
                                @class(['shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium',
                                        'border-blue-600 bg-blue-50 text-blue-700' => $activeCategory?->id === $sub->id,
                                        'border-slate-200 bg-white text-slate-600 hover:border-blue-300' => $activeCategory?->id !== $sub->id])>
                            {{ $sub->name }}
                        </button>
                    @endforeach
                </nav>
            @endif
        @endif

        @if ($search === '' && ! $activeCategory && $stores->isNotEmpty())
            <section class="mt-6" aria-labelledby="shops-heading">
                <h2 id="shops-heading" class="text-lg font-bold">{{ $t('shop.home.near_you') }}</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($stores as $s)
                        <a href="{{ route('customer.shop.store', [$city->slug, $s->slug]) }}" wire:navigate
                           class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 hover:border-blue-300">
                            @if ($s->logo_url)
                                <img src="{{ $s->logo_url }}" alt="" class="h-14 w-14 shrink-0 rounded-xl object-cover">
                            @else
                                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl font-bold text-blue-700">{{ mb_substr($s->name, 0, 1) }}</span>
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-slate-900">{{ $s->name }}</span>
                                <span class="block text-xs text-slate-500">{{ $s->products_count === 1 ? $t('shop.item_one') : $t('shop.item_many', ['count' => $s->products_count]) }}
                                    · @if ((float) $s->delivery_fee_flat > 0) {{ $t('shop.delivery_fee', ['amount' => $currencySymbol.number_format((float) $s->delivery_fee_flat, 0)]) }}@else {{ $t('shop.free_delivery') }} @endif</span>
                                <span @class(['mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-50 text-emerald-700' => $s->is_open, 'bg-slate-100 text-slate-500' => ! $s->is_open])>{{ $s->is_open ? $t('shop.open_now') : $t('shop.closed') }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="mt-6" aria-labelledby="items-heading">
            <h2 id="items-heading" class="text-lg font-bold">
                @if ($search !== '') {{ $t('shop.home.results_for', ['term' => $search]) }} @elseif ($activeCategory) {{ $activeCategory->name }} @else {{ $t('shop.home.everything_in', ['city' => $city->name]) }} @endif
            </h2>
            @if ($products->isEmpty())
                <p class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                    {{ $search !== '' ? $t('shop.home.no_match') : $t('shop.home.coming_soon', ['city' => $city->name]) }}
                </p>
            @else
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($products as $p)
                        <x-shop.product-card :product="$p" :city="$city" :qty="$inCart[$p->id] ?? 0" :currency-symbol="$currencySymbol" />
                    @endforeach
                </div>
                <div class="mt-4">{{ $products->links() }}</div>
            @endif
        </section>

        {{-- E-commerce Phase 5: the grievance officer (E-Commerce Rules 2020), from Shop Settings. --}}
        @php $grievance = array_values(array_filter(preg_split('/\R/', (string) \App\Support\MarketplaceSettings::get('marketplace.grievance_officer')))); @endphp
        @if ($grievance)
            <aside class="mt-10 rounded-2xl bg-slate-50 p-4 text-xs text-slate-600">
                <p class="font-semibold text-slate-800">{{ $t('shop.grievance_officer') }}</p>
                @foreach ($grievance as $line)<p>{{ $line }}</p>@endforeach
            </aside>
        @endif
    </div>

    <livewire:customer.shop.cart-bar />
    <livewire:customer.shop.choice-sheet />
</div>
