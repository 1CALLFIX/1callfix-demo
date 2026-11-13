<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $chip = 'inline-flex min-h-10 shrink-0 items-center rounded-full border px-3 text-sm font-medium transition';
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <span>{{ $t('hall.crumb') }}</span>
    </nav>
    <div class="mt-2 flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-blue-600">{{ $t('hall.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('hall.home.title') }}</h1>
        </div>
        <x-customer.share-button :title="$t('hall.meta.title', ['city' => $city->name])" />
    </div>

    @if ($kinds->count() > 1)
        <div class="relative mt-4 flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Kind of hall">
            <button type="button" wire:click="$set('kind', '')" @class([$chip, 'border-blue-600 bg-blue-600 text-white' => $kind === '', 'border-slate-300 bg-white text-slate-700' => $kind !== ''])>{{ $t('hall.home.all') }}</button>
            @foreach ($kinds as $k)
                <button type="button" wire:click="$set('kind', @js($k))" @class([$chip, 'border-blue-600 bg-blue-600 text-white' => $kind === $k, 'border-slate-300 bg-white text-slate-700' => $kind !== $k])>{{ $k }}</button>
            @endforeach
        </div>
    @endif

    <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="hall-cards">
        @forelse ($halls as $h)
            <?php $c = $cards[$h->id]; ?>
            <li class="relative">
                <a href="{{ $c['url'] }}" wire:navigate class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                    <div class="aspect-[3/2] w-full bg-slate-100">
                        @if ($c['cover']) <img src="{{ $c['cover'] }}" alt="{{ $h->name }}" class="h-full w-full object-cover" loading="lazy"> @endif
                    </div>
                    <div class="flex flex-1 flex-col gap-1 p-4">
                        <p class="text-xs font-semibold uppercase text-blue-600">{{ $h->hall_type }}</p>
                        <h2 class="text-lg font-semibold leading-tight">{{ $h->name }}</h2>
                        <p class="text-sm text-slate-500">{{ collect([$c['seats'], $c['ac']])->filter()->implode(' · ') }}</p>
                        @if ($c['from']) <p class="mt-auto pt-2 text-sm font-semibold text-slate-900">{{ $c['from'] }}</p> @endif
                    </div>
                </a>
                <x-customer.item-actions class="absolute right-3 top-3" :item="'hall:'.$h->id" :title="$h->name" :image="$c['cover']" />
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600 sm:col-span-2 lg:col-span-3">{{ $t('hall.home.empty') }}</li>
        @endforelse
    </ul>
</div>
