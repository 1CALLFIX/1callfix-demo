<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $chip = 'inline-flex min-h-11 shrink-0 items-center rounded-full border px-4 text-sm font-medium transition';
    $on = 'border-sky-600 bg-sky-600 text-white';
    $off = 'border-slate-300 bg-white text-slate-700 hover:border-sky-400';
    // One row of tap filters: [label, model, options value => label]
    $rows = [
        [$t('vehicles.filter.kind'), 'kind', array_combine($kinds, $kinds) ?: []],
        [$t('vehicles.filter.how'), 'how', array_combine($hows, $hows) ?: []],
    ];
    $more = [
        [$t('vehicles.filter.fuel'), 'fuel', array_combine($fuels, $fuels) ?: []],
        [$t('vehicles.filter.area'), 'area', $areas->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all()],
    ];
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6" x-data="{ more: {{ ($fuel.$area) !== '' ? 'true' : 'false' }} }">
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.rentals.home', $city->slug) }}" wire:navigate class="hover:underline">{{ $t('rental.crumb') }}</a> ›
        <span>{{ $t('vehicles.crumb') }}</span>
    </nav>
    <div class="mt-2 flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-sky-700">{{ $t('vehicles.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('vehicles.home.title') }}</h1>
        </div>
        <x-customer.share-button :title="$t('vehicles.meta.title', ['city' => $city->name])" />
    </div>

    <div class="mt-4 space-y-3 rounded-2xl border border-sky-100 bg-sky-50/50 p-3" data-testid="vehicle-filters">
        @foreach ($rows as [$label, $model, $options])
            @if ($options !== [])
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-800">{{ $label }}</p>
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
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-800">{{ $label }}</p>
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
            <button type="button" @click="more = ! more" class="min-h-11 rounded-full border border-sky-300 bg-white px-4 text-sm font-semibold text-sky-800"><span x-text="more ? @js($t('homes.filter.fewer')) : @js($t('homes.filter.more'))"></span></button>
            <button type="button" wire:click="$set('sort', @js($sort === 'cheap' ? '' : 'cheap'))" @class([$chip, $on => $sort === 'cheap', $off => $sort !== 'cheap'])>{{ $t('vehicles.filter.cheapest') }}</button>
            @if ($filtered) <button type="button" wire:click="clearFilters" class="min-h-11 px-2 text-sm font-medium text-slate-600 underline">{{ $t('homes.filter.clear') }}</button> @endif
        </div>
    </div>

    <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="vehicle-cards" wire:loading.class="opacity-60">
        @forelse ($vehicles as $v)
            <?php $c = $cards[$v->id]; ?>
            <li class="relative" wire:key="vehicle-card-{{ $v->id }}">
                <a href="{{ $c['url'] }}" wire:navigate class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                    <div class="relative aspect-[3/2] w-full bg-slate-100">
                        @if ($c['cover']) <img src="{{ $c['cover'] }}" alt="{{ $v->title }}" class="h-full w-full object-cover" loading="lazy"> @endif
                        @if ($c['price']) <span class="absolute bottom-2 left-2 rounded-full bg-sky-600 px-3 py-1 text-sm font-bold text-white shadow">{{ $c['price'] }}</span> @endif
                    </div>
                    <div class="flex flex-1 flex-col gap-1 p-4">
                        <p class="text-xs font-semibold uppercase text-sky-700">{{ $c['what'] }}</p>
                        <h2 class="text-base font-semibold leading-tight">{{ $v->title }}</h2>
                        @if ($c['where']) <p class="text-sm text-slate-500">📍 {{ $c['where'] }}</p> @endif
                        @if ($c['how'])
                            <p class="mt-auto flex flex-wrap gap-2 pt-2 text-xs">
                                @foreach ((array) $v->driver_options as $h) <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">{{ $h }}</span> @endforeach
                            </p>
                        @endif
                    </div>
                </a>
                <x-customer.item-actions class="absolute right-3 top-3" :item="'vehicle:'.$v->id" :title="$v->title" :image="$c['cover']" />
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600 sm:col-span-2 lg:col-span-3">
                {{ $filtered ? $t('vehicles.home.none_filtered') : $t('vehicles.home.empty') }}
            </li>
        @endforelse
    </ul>
</div>
