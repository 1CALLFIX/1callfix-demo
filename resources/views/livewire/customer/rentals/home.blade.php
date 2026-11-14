<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <span>{{ $t('rental.crumb') }}</span>
    </nav>
    <div class="mt-2 flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-teal-700">{{ $t('rental.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('rental.home.title') }}</h1>
        </div>
        <x-customer.share-button :title="$t('rental.meta.title', ['city' => $city->name])" />
    </div>

    {{-- One big coloured tile per kind live here. --}}
    <ul class="mt-5 grid gap-3 sm:grid-cols-2" data-testid="rental-kinds">
        @foreach ($sections as $s)
            <li>
                <a href="{{ $s['url'] }}" wire:navigate class="flex min-h-24 items-center gap-4 rounded-2xl border-2 border-teal-100 bg-teal-50 p-4 transition hover:border-teal-400" data-kind="{{ $s['kind'] }}">
                    <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-teal-600 text-3xl" aria-hidden="true">{{ $s['icon'] }}</span>
                    <span class="flex-1">
                        <span class="block text-lg font-semibold text-teal-900">{{ $s['title'] }}</span>
                        <span class="block text-sm text-teal-800">{{ $s['line'] }}</span>
                    </span>
                    <span class="shrink-0 rounded-full bg-white px-3 py-1 text-sm font-semibold text-teal-800">{{ $s['count'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @foreach ($sections as $s)
        @if ($s['items'] !== [])
            <section class="mt-8" data-testid="rental-section-{{ $s['kind'] }}">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-xl font-semibold">{{ $s['title'] }}</h2>
                    <a href="{{ $s['url'] }}" wire:navigate class="flex min-h-11 items-center text-sm font-semibold text-teal-700 hover:underline">{{ $t('rental.home.see_all', ['count' => $s['count']]) }} →</a>
                </div>
                <ul class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($s['items'] as $item)
                        <li class="relative">
                            <a href="{{ $item['url'] }}" wire:navigate class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
                                <div class="aspect-[3/2] w-full bg-slate-100">
                                    @if ($item['cover']) <img src="{{ $item['cover'] }}" alt="{{ $item['name'] }}" class="h-full w-full object-cover" loading="lazy"> @endif
                                </div>
                                <div class="flex flex-1 flex-col gap-1 p-4">
                                    <p class="text-xs font-semibold uppercase text-teal-700">{{ $item['kind'] }}</p>
                                    <h3 class="text-lg font-semibold leading-tight">{{ $item['name'] }}</h3>
                                    <p class="text-sm text-slate-500">{{ collect([$item['seats'], $item['ac']])->filter()->implode(' · ') }}</p>
                                    @if ($item['from']) <p class="mt-auto pt-2 text-sm font-semibold text-slate-900">{{ $item['from'] }}</p> @endif
                                </div>
                            </a>
                            <x-customer.item-actions class="absolute right-3 top-3" :item="$item['id']" :title="$item['name']" :image="$item['cover']" />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
</div>
