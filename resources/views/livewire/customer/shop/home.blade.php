<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => 'Home', 'url' => route('customer.home')],
            ['label' => $city->name, 'url' => route('customer.city.show', $city->slug)],
            ['label' => 'Shop', 'url' => null],
        ]" />

        {{-- Our own opening: the city's shops, not a faceless catalogue. --}}
        <header class="mt-4 rounded-3xl bg-gradient-to-br from-blue-600 to-blue-800 px-5 py-6 text-white sm:px-8 sm:py-8">
            <p class="text-xs font-semibold uppercase tracking-widest text-blue-100">Shops of {{ $city->name }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">Buy from the shops you know, delivered today</h1>
            <p class="mt-1 max-w-xl text-sm text-blue-100">Every order comes from a verified local shop and a 1CallFix rider you can track. Or pick it up yourself.</p>
            <label class="mt-4 block max-w-xl">
                <span class="sr-only">Search the shops of {{ $city->name }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search a product or a shop"
                       class="block min-h-12 w-full rounded-xl border-0 px-4 text-base text-slate-900 shadow-sm focus:outline focus:outline-2 focus:outline-white">
            </label>
        </header>

        @if ($cartMessage)
            <p role="status" class="mt-4 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $cartMessage }}</p>
        @endif

        @if ($categories->isNotEmpty())
            <nav aria-label="Categories" class="mt-5 flex gap-2 overflow-x-auto pb-1">
                @foreach ($categories as $c)
                    <button type="button" wire:click="pickCategory('{{ $c->slug }}')"
                            @class(['shrink-0 rounded-full border px-4 py-2 text-sm font-medium',
                                    'border-blue-600 bg-blue-600 text-white' => $activeCategory?->id === $c->id,
                                    'border-slate-300 bg-white text-slate-700 hover:border-blue-400' => $activeCategory?->id !== $c->id])>
                        {{ $c->name }}
                    </button>
                @endforeach
            </nav>
        @endif

        @if ($search === '' && ! $activeCategory && $stores->isNotEmpty())
            <section class="mt-6" aria-labelledby="shops-heading">
                <h2 id="shops-heading" class="text-lg font-bold">Shops near you</h2>
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
                                <span class="block text-xs text-slate-500">{{ $s->products_count }} {{ $s->products_count === 1 ? 'item' : 'items' }}
                                    · @if ((float) $s->delivery_fee_flat > 0) Delivery {{ $currencySymbol }}{{ number_format((float) $s->delivery_fee_flat, 0) }}@else Free delivery @endif</span>
                                <span @class(['mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-50 text-emerald-700' => $s->is_open, 'bg-slate-100 text-slate-500' => ! $s->is_open])>{{ $s->is_open ? 'Open now' : 'Closed' }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="mt-6" aria-labelledby="items-heading">
            <h2 id="items-heading" class="text-lg font-bold">
                @if ($search !== '') Results for “{{ $search }}” @elseif ($activeCategory) {{ $activeCategory->name }} @else Everything in {{ $city->name }} @endif
            </h2>
            @if ($products->isEmpty())
                <p class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                    {{ $search !== '' ? 'Nothing matches that yet. Try a shorter word.' : 'Shops in '.$city->name.' are adding their products. Check back soon.' }}
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
                <p class="font-semibold text-slate-800">Grievance officer</p>
                @foreach ($grievance as $line)<p>{{ $line }}</p>@endforeach
            </aside>
        @endif
    </div>

    <livewire:customer.shop.cart-bar />
</div>
