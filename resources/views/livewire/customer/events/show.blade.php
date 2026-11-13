<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.events.home', $city->slug) }}" wire:navigate class="hover:underline">{{ $t('event.crumb') }}</a> ›
        <span>{{ $event->title }}</span>
    </nav>

    <div class="relative mt-3 overflow-hidden rounded-2xl bg-slate-100">
        @if ($card['cover']) <img src="{{ $card['cover'] }}" alt="{{ $event->title }}" class="aspect-video w-full object-cover"> @else <div class="aspect-video w-full"></div> @endif
        <x-customer.item-actions class="absolute right-3 top-3" :item="'event:'.$event->id" :title="$event->title" :image="$card['cover']" />
    </div>

    <div class="mt-5 grid gap-6 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <div>
                <p class="text-sm font-semibold text-blue-600">{{ $event->category }}</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $event->title }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $t('event.show.organiser', ['name' => $event->organiser?->name]) }}</p>
            </div>

            <dl class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
                <div><dt class="font-semibold text-slate-500">{{ $t('event.show.when') }}</dt><dd class="mt-0.5 text-slate-900">{{ $when }}</dd></div>
                <div>
                    <dt class="font-semibold text-slate-500">{{ $t('event.show.where') }}</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $event->venue_name }}, {{ $event->venue_address }}
                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="ml-1 font-medium text-blue-700 underline">{{ $t('event.show.directions') }}</a></dd>
                </div>
                @if ($event->min_age)
                    <div><dd class="inline-block rounded-full bg-amber-100 px-3 py-1 font-semibold text-amber-900">{{ $t('event.show.age', ['age' => $event->min_age]) }}</dd></div>
                @endif
            </dl>

            @if ($event->description)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('event.show.about') }}</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $event->description }}</p>
                </section>
            @endif
            @if ($event->terms)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('event.show.rules') }}</h2>
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $event->terms }}</p>
                </section>
            @endif
        </div>

        <aside class="lg:col-span-1">
            <form method="GET" action="{{ route('customer.events.book') }}" class="sticky top-20 space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="event-tickets">
                <input type="hidden" name="event" value="{{ $event->id }}">
                <h2 class="font-semibold">{{ $t('event.buy.choose') }}</h2>
                @foreach ($tickets as $tk)
                    <div class="flex items-start justify-between gap-3 border-t pt-3 first-of-type:border-t-0" data-testid="event-ticket-type">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $tk['type']->name }}</p>
                            @if ($tk['type']->description) <p class="text-xs text-slate-500">{{ $tk['type']->description }}</p> @endif
                            @if ($tk['admits']) <p class="text-xs text-slate-500">{{ $tk['admits'] }}</p> @endif
                            <p class="mt-0.5 text-sm font-semibold">{{ $tk['price'] }}</p>
                            @if ($tk['left']) <p class="text-xs font-medium text-amber-700">{{ $tk['left'] }}</p> @endif
                            @if ($tk['ends']) <p class="text-xs text-slate-500">{{ $tk['ends'] }}</p> @endif
                        </div>
                        @if ($onSale && $tk['available'] && $taxesKnown)
                            <select name="t[{{ $tk['type']->id }}]" aria-label="{{ $tk['type']->name }}" class="min-h-11 w-20 shrink-0 rounded-lg border border-slate-300 bg-white px-2 text-base">
                                @for ($i = 0; $i <= min($tk['max'], $maxOrder); $i++) <option value="{{ $i }}">{{ $i }}</option> @endfor
                            </select>
                        @elseif (! $tk['available'])
                            <span class="shrink-0 text-sm font-semibold text-red-700">{{ $t('event.card.sold_out') }}</span>
                        @endif
                    </div>
                @endforeach
                @if (! $onSale)
                    <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('event.show.closed') }}</p>
                @elseif (! $taxesKnown)
                    <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('event.buy.opening_soon') }}</p>
                @elseif (! $card['soldOut'])
                    <button type="submit" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="event-continue">{{ $t('event.buy.choose') }} →</button>
                    <p class="text-xs text-slate-500">{{ $t('event.buy.no_refund') }}</p>
                @endif
            </form>
        </aside>
    </div>
</div>
