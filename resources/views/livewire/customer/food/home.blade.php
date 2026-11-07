@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
{{-- Food F3: the city's restaurants. 1CallFix's own layout: a plain list, open first, the facts on each card. --}}
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => $t('shop.crumb_home'), 'url' => route('customer.home')],
            ['label' => $city->name, 'url' => route('customer.city.show', $city->slug)],
            ['label' => $t('food.crumb'), 'url' => null],
        ]" />

        <header class="mt-4">
            <p class="text-xs font-semibold uppercase tracking-widest text-[var(--mod-ink,#1D4ED8)]">{{ $t('food.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $t('food.home.title') }}</h1>
            <x-customer.share-button :title="$t('food.home.title')" class="mt-3" />
            <p class="mt-1 max-w-xl text-sm text-slate-600">{{ $t('food.home.intro') }}</p>
            <label class="mt-4 block max-w-xl">
                <span class="sr-only">{{ $t('food.home.search_label') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $t('food.home.search_placeholder') }}"
                       class="block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-base shadow-sm placeholder:text-slate-400" data-testid="food-search">
            </label>
        </header>

        <nav aria-label="{{ $t('food.home.filters') }}" class="mt-4 flex gap-2 overflow-x-auto pb-1">
            <button type="button" wire:click="$toggle('vegOnly')" aria-pressed="{{ $vegOnly ? 'true' : 'false' }}"
                    @class(['inline-flex shrink-0 items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-medium',
                            'border-emerald-600 bg-emerald-600 text-white' => $vegOnly, 'border-slate-300 bg-white text-slate-700' => ! $vegOnly])>
                <x-food-mark type="veg" /> {{ $t('food.home.veg_only') }}
            </button>
            @foreach ($cuisines as $c)
                <button type="button" wire:click="pickCuisine(@js($c))" aria-pressed="{{ $cuisine === $c ? 'true' : 'false' }}"
                        @class(['shrink-0 rounded-full border px-4 py-2 text-sm font-medium',
                                'border-[var(--mod,#2563EB)] bg-[var(--mod,#2563EB)] text-white' => $cuisine === $c, 'border-slate-300 bg-white text-slate-700 hover:border-[var(--mod,#2563EB)]' => $cuisine !== $c])>{{ $c }}</button>
            @endforeach
        </nav>

        <section class="mt-5" aria-labelledby="restaurants-heading">
            <h2 id="restaurants-heading" class="text-lg font-bold text-slate-900">
                {{ $search !== '' ? $t('food.home.results_for', ['term' => $search]) : $t('food.home.restaurants_in', ['city' => $city->name]) }}
            </h2>

            @if ($cards->isEmpty())
                <p class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                    {{ ($search !== '' || $cuisine !== '' || $vegOnly) ? $t('food.home.no_match') : $t('food.home.coming_soon', ['city' => $city->name]) }}
                </p>
            @else
                <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($cards as $c)
                        @php $s = $c['store']; @endphp
                        <li wire:key="r-{{ $s->id }}" class="relative">
                            <a href="{{ $c['url'] }}" wire:navigate data-testid="restaurant-card"
                               @class(['cf-card block overflow-hidden', 'opacity-70' => ! $c['open']])>
                                {{-- Restaurant portal 2026-11-04: the cover photo, wide, on top. --}}
                                @if ($s->cover_url)
                                    <img src="{{ $s->cover_url }}" alt="" loading="lazy" @class(['h-36 w-full object-cover', 'grayscale' => ! $c['open']]) data-testid="restaurant-cover">
                                @endif
                                <span class="flex gap-3 p-3">
                                @if ($s->logo_url)
                                    <img src="{{ $s->logo_url }}" alt="" loading="lazy" @class(['h-20 w-20 shrink-0 rounded-xl object-cover', 'grayscale' => ! $c['open']])>
                                @else
                                    <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-2xl font-bold text-orange-700">{{ mb_substr($s->name, 0, 1) }}</span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span @class(['flex items-start justify-between gap-2', 'pr-9' => ! $s->cover_url])>
                                        <span class="truncate font-semibold text-slate-900">{{ $s->name }}</span>
                                        @if ($c['rating'])<span class="shrink-0 rounded-md bg-emerald-600 px-1.5 py-0.5 text-xs font-bold leading-4 text-white" data-rating-chip>{{ $c['rating']['avg'] }} ★</span>@endif
                                    </span>
                                    @if ($c['cuisines'])<span class="block truncate text-xs text-slate-500">{{ $c['cuisines'] }}</span>@endif
                                    <span class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-xs text-slate-700">
                                        @if ($c['open'])
                                            <span class="font-semibold">{{ $t('food.cooks_in', ['minutes' => $c['minutes']]) }}</span>
                                        @endif
                                        <span>{{ $c['fee'] }}</span>
                                        @if ($c['costForTwo'])<span>{{ $c['costForTwo'] }}</span>@endif
                                    </span>
                                    <span class="mt-1.5 flex flex-wrap gap-1.5 text-[11px] font-semibold">
                                        @if ($c['open'])
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ $t('food.open_now') }}</span>
                                        @else
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">{{ $c['opensAt'] ?? $t('food.closed_now') }}</span>
                                        @endif
                                        @if ($c['pureVeg'])<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700"><x-food-mark type="veg" /> {{ $t('food.pure_veg') }}</span>@endif
                                        @if ($c['open'] && $c['busy'])<span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800">{{ $t('food.busy') }}</span>@endif
                                    </span>
                                </span>
                                </span>
                            </a>
                            {{-- ♡ on the card corner (2026-11-07), outside the link. --}}
                            <x-customer.save-button small :item="'store:'.$s->id" :title="$s->name" :image="$s->cover_url ?: $s->logo_url" :url="$c['url']" class="absolute right-2 top-2 z-10" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <livewire:customer.shop.cart-bar />
</div>
