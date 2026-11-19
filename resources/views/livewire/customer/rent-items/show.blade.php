<?php
    // Rent anything and Second-hand ($deal, 2026-11-19) share this page; words come from PostTypes::text.
    $t = fn ($k, $r = []) => \App\Support\PostTypes::text($deal, preg_replace('/^items\./', '', $k), $r);
    $homeRoute = \App\Support\PostTypes::route($deal, 'home');
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route($homeRoute, $city->slug) }}" wire:navigate class="hover:underline">{{ $t('items.crumb') }}</a> ›
        <span>{{ $item->name }}</span>
    </nav>

    <x-customer.photo-gallery class="mt-3" :photos="$photos" :title="$item->title" :item="'rentitem:'.$item->id" :cover="$card['cover']" />

    <div class="mt-5 grid gap-6 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <div>
                @if ($card['what']) <p class="text-sm font-semibold text-violet-700">{{ $card['what'] }}</p> @endif
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $item->title }}</h1>
                @if ($card['where']) <p class="mt-1 text-sm text-slate-600">📍 {{ $card['where'] }}</p> @endif
            </div>

            @if ($sold)
                <p role="status" class="rounded-xl bg-violet-100 px-4 py-3 text-sm font-semibold text-violet-900" data-testid="rent-sold">✅ {{ $t('show.sold_note') }}</p>
            @endif
            @if ($badges !== [])
                <p class="flex flex-wrap gap-2">
                    @foreach ($badges as [$badge, $tone])
                        <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $tone }}" @if ($loop->first && $item->can_deliver) data-testid="rent-deliver" @endif>{{ $badge }}</span>
                    @endforeach
                </p>
            @endif

            <ul class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4" data-testid="rent-facts">
                @foreach ($facts as [$label, $value, $tone])
                    <li class="rounded-xl p-3 {{ $tone }}"><p class="text-xs opacity-80">{{ $label }}</p><p class="font-semibold">{{ $value }}</p></li>
                @endforeach
            </ul>

            @if ($item->about)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('items.show.about') }}</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $item->about }}</p>
                </section>
            @endif

            <p class="rounded-xl bg-slate-100 px-3 py-2 text-sm text-slate-700">🤝 {{ $t('items.show.deal_note') }}</p>
        </div>

        <aside class="lg:col-span-1">
            <div class="sticky top-20">
                @if ($sold)
                    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="rent-contact-sold">
                        <p class="text-2xl font-bold text-slate-400 line-through">{{ $card['price'] }}</p>
                        <p class="inline-flex rounded-full bg-violet-600 px-3 py-1 text-sm font-bold text-white">{{ $t('show.sold') }}</p>
                        <a href="{{ route($homeRoute, $city->slug) }}" wire:navigate class="flex min-h-12 w-full items-center justify-center rounded-xl bg-violet-600 px-4 font-semibold text-white hover:bg-violet-700">{{ $t('show.more') }}</a>
                    </div>
                @else
                    <livewire:customer.rent-items.contact :item-id="$item->id" :key="'rcontact-'.$item->id" />
                @endif
            </div>
        </aside>
    </div>
</div>
