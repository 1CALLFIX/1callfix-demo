<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $chip = 'inline-flex min-h-11 shrink-0 items-center rounded-full border px-4 text-sm font-medium transition';
    $on = 'border-emerald-600 bg-emerald-600 text-white';
    $off = 'border-slate-300 bg-white text-slate-700 hover:border-emerald-400';
    // One row of tap filters: [label, model, options value => label]
    $rows = [
        [$t('homes.filter.budget'), 'budget', $budgets],
        [$t('homes.filter.size'), 'size', array_combine($sizes, $sizes) ?: []],
    ];
    $more = [
        [$t('homes.filter.type'), 'type', array_combine($types, $types) ?: []],
        [$t('homes.filter.furnishing'), 'furnishing', array_combine($furnishings, $furnishings) ?: []],
        [$t('homes.filter.for'), 'tenant', array_combine($tenantChoices, $tenantChoices) ?: []],
        [$t('homes.filter.area'), 'area', $areas->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all()],
    ];
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6" x-data="{ more: {{ ($type.$furnishing.$tenant.$area) !== '' ? 'true' : 'false' }} }">
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.rentals.home', $city->slug) }}" wire:navigate class="hover:underline">{{ $t('rental.crumb') }}</a> ›
        <span>{{ $t('homes.crumb') }}</span>
    </nav>
    <div class="mt-2 flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-emerald-700">{{ $t('homes.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('homes.home.title') }}</h1>
        </div>
        <x-customer.share-button :title="$t('homes.meta.title', ['city' => $city->name])" />
    </div>

    <div class="mt-4 space-y-3 rounded-2xl border border-emerald-100 bg-emerald-50/50 p-3" data-testid="home-filters">
        @foreach ($rows as [$label, $model, $options])
            @if ($options !== [])
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">{{ $label }}</p>
                    <div class="mt-1.5 flex gap-2 overflow-x-auto pb-1">
                        @foreach ($options as $value => $text)
                            <button type="button" wire:click="$set('{{ $model }}', @js($$model === (string) $value ? '' : (string) $value))" @class([$chip, $on => $$model === (string) $value, $off => $$model !== (string) $value])>{{ $text }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
        <div x-show="more" x-cloak class="space-y-3">
            @foreach ($more as [$label, $model, $options])
                @if ($options !== [])
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">{{ $label }}</p>
                        <div class="mt-1.5 flex gap-2 overflow-x-auto pb-1">
                            @foreach ($options as $value => $text)
                                <button type="button" wire:click="$set('{{ $model }}', @js($$model === (string) $value ? '' : (string) $value))" @class([$chip, $on => $$model === (string) $value, $off => $$model !== (string) $value])>{{ $text }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="more = ! more" class="min-h-11 rounded-full border border-emerald-300 bg-white px-4 text-sm font-semibold text-emerald-800"><span x-text="more ? @js($t('homes.filter.fewer')) : @js($t('homes.filter.more'))"></span></button>
            <button type="button" wire:click="$set('sort', @js($sort === 'cheap' ? '' : 'cheap'))" @class([$chip, $on => $sort === 'cheap', $off => $sort !== 'cheap'])>{{ $t('homes.filter.cheapest') }}</button>
            @if ($filtered) <button type="button" wire:click="clearFilters" class="min-h-11 px-2 text-sm font-medium text-slate-600 underline">{{ $t('homes.filter.clear') }}</button> @endif
        </div>
    </div>

    <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="home-cards" wire:loading.class="opacity-60">
        @forelse ($homes as $h)
            <?php $c = $cards[$h->id]; ?>
            <li class="relative" wire:key="home-card-{{ $h->id }}">
                <a href="{{ $c['url'] }}" wire:navigate class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                    <div class="relative aspect-[3/2] w-full bg-slate-100">
                        @if ($c['cover']) <img src="{{ $c['cover'] }}" alt="{{ $h->title }}" class="h-full w-full object-cover" loading="lazy"> @endif
                        <span class="absolute bottom-2 left-2 rounded-full bg-emerald-600 px-3 py-1 text-sm font-bold text-white shadow">{{ $c['rent'] }}</span>
                    </div>
                    <div class="flex flex-1 flex-col gap-1 p-4">
                        <p class="text-xs font-semibold uppercase text-emerald-700">{{ $c['what'] }}</p>
                        <h2 class="text-base font-semibold leading-tight">{{ $h->title }}</h2>
                        @if ($c['where']) <p class="text-sm text-slate-500">📍 {{ $c['where'] }}</p> @endif
                        <p class="mt-auto flex flex-wrap gap-2 pt-2 text-xs">
                            @if ($c['furnishing']) <span class="rounded-full bg-sky-100 px-2 py-0.5 font-medium text-sky-800">{{ $c['furnishing'] }}</span> @endif
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">{{ $c['ready'] }}</span>
                        </p>
                    </div>
                </a>
                <x-customer.item-actions class="absolute right-3 top-3" :item="'home:'.$h->id" :title="$h->title" :image="$c['cover']" />
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600 sm:col-span-2 lg:col-span-3">
                {{ $filtered ? $t('homes.home.none_filtered') : $t('homes.home.empty') }}
            </li>
        @endforelse
    </ul>
</div>
