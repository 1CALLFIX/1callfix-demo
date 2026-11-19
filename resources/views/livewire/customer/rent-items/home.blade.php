<?php
    // Rent anything and Second-hand ($deal, 2026-11-19) share this page; words come from PostTypes::text.
    $t = fn ($k, $r = []) => \App\Support\PostTypes::text($deal, preg_replace('/^items\./', '', $k), $r);
    $sale = $deal === \App\Support\PostTypes::SELL;
    $chip = 'inline-flex min-h-11 shrink-0 items-center rounded-full border px-4 text-sm font-medium transition';
    $on = 'border-violet-600 bg-violet-600 text-white';
    $off = 'border-slate-300 bg-white text-slate-700 hover:border-violet-400';
    // Rows of tap filters: [label, model, options value => label]
    $rows = [
        [$t('items.filter.kind'), 'kind', array_combine($kinds, $kinds) ?: []],
        [$t('items.filter.area'), 'area', $areas->count() > 1 ? $areas->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all() : []],
    ];
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        @unless ($sale)
        <a href="{{ route('customer.rentals.home', $city->slug) }}" wire:navigate class="hover:underline">{{ \App\Support\SiteText::get('rental.crumb') }}</a> ›
        @endunless
        <span>{{ $t('items.crumb') }}</span>
    </nav>
    <div class="mt-2 flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-violet-700">{{ $t('items.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('items.home.title') }}</h1>
        </div>
        <x-customer.share-button :title="$t('items.meta.title', ['city' => $city->name])" />
    </div>
    <a href="{{ route($createRoute) }}" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-full bg-violet-50 px-4 text-sm font-semibold text-violet-800 ring-1 ring-violet-200 hover:bg-violet-100" data-testid="rent-post">➕ {{ $t('items.home.post') }}</a>

    <div class="mt-4 space-y-3 rounded-2xl border border-violet-100 bg-violet-50/50 p-3" data-testid="rent-filters">
        @foreach ($rows as [$label, $model, $options])
            @if ($options !== [])
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-violet-800">{{ $label }}</p>
                    <div class="mt-1.5 flex gap-2 overflow-x-auto pb-1">
                        @foreach ($options as $value => $text)
                            <button type="button" wire:click="$set('{{ $model }}', @js($$model === (string) $value ? '' : (string) $value))" @class([$chip, $on => $$model === (string) $value, $off => $$model !== (string) $value])>{{ $text }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('deliver', @js($deliver === '1' ? '' : '1'))" @class([$chip, $on => $deliver === '1', $off => $deliver !== '1'])>{{ $t('items.filter.deliver') }}</button>
            <button type="button" wire:click="$set('sort', @js($sort === 'cheap' ? '' : 'cheap'))" @class([$chip, $on => $sort === 'cheap', $off => $sort !== 'cheap'])>{{ $t('items.filter.cheapest') }}</button>
            @if ($filtered) <button type="button" wire:click="clearFilters" class="min-h-11 px-2 text-sm font-medium text-slate-600 underline">{{ \App\Support\SiteText::get('homes.filter.clear') }}</button> @endif
        </div>
    </div>

    <ul class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3" data-testid="rent-cards" wire:loading.class="opacity-60">
        @forelse ($items as $i)
            <?php $c = $cards[$i->id]; ?>
            <li class="relative" wire:key="rent-card-{{ $i->id }}">
                <a href="{{ $c['url'] }}" wire:navigate class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                    <div class="relative aspect-square w-full bg-slate-100 sm:aspect-[3/2]">
                        @if ($c['cover']) <img src="{{ $c['cover'] }}" alt="{{ $i->title }}" class="h-full w-full object-cover" loading="lazy"> @endif
                        @if ($c['price']) <span class="absolute bottom-2 left-2 rounded-full bg-violet-600 px-3 py-1 text-sm font-bold text-white shadow">{{ $c['price'] }}</span> @endif
                    </div>
                    <div class="flex flex-1 flex-col gap-1 p-3 sm:p-4">
                        @if ($c['what']) <p class="text-xs font-semibold uppercase text-violet-700">{{ $c['what'] }}</p> @endif
                        <h2 class="text-sm font-semibold leading-tight sm:text-base">{{ $i->name }}</h2>
                        @if ($c['where']) <p class="text-xs text-slate-500 sm:text-sm">📍 {{ $c['where'] }}</p> @endif
                        @if ($i->can_deliver) <p class="mt-auto pt-1 text-xs"><span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-800">{{ $t('items.filter.deliver') }}</span></p> @endif
                    </div>
                </a>
                <x-customer.item-actions class="absolute right-2 top-2" :item="'rentitem:'.$i->id" :title="$i->title" :image="$c['cover']" />
            </li>
        @empty
            <li class="col-span-2 rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600 lg:col-span-3">
                {{ $filtered ? $t('items.home.none_filtered') : $t('items.home.empty') }}
                @unless ($filtered)
                    <p class="mt-4"><a href="{{ route($createRoute) }}" class="inline-flex min-h-11 items-center gap-2 rounded-full bg-violet-600 px-5 font-semibold text-white hover:bg-violet-700" data-testid="rent-post-empty">➕ {{ $t('items.home.post') }}</a></p>
                @endunless
            </li>
        @endforelse
    </ul>
</div>
