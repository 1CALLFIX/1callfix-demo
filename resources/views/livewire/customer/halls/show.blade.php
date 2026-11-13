<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $field = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600';
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.halls.home', $city->slug) }}" wire:navigate class="hover:underline">{{ $t('hall.crumb') }}</a> ›
        <span>{{ $hall->name }}</span>
    </nav>

    <div class="relative mt-3">
        <ul class="flex snap-x gap-2 overflow-x-auto rounded-2xl [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="{{ $t('hall.show.photos') }}">
            @forelse ($photos as $i => $p)
                <li class="w-full shrink-0 snap-start sm:w-[85%]">
                    <img src="{{ $p }}" alt="{{ $hall->name }} {{ $i + 1 }}" class="aspect-[3/2] w-full rounded-2xl object-cover" @if ($i > 0) loading="lazy" @endif>
                </li>
            @empty
                <li class="aspect-[3/2] w-full rounded-2xl bg-slate-100"></li>
            @endforelse
        </ul>
        <x-customer.item-actions class="absolute right-3 top-3" :item="'hall:'.$hall->id" :title="$hall->name" :image="$card['cover']" />
    </div>

    <div class="mt-5 grid gap-6 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <div>
                <p class="text-sm font-semibold text-blue-600">{{ $hall->hall_type }}</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $hall->name }}</h1>
            </div>

            <ul class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4" data-testid="hall-facts">
                @if ($hall->seating_capacity) <li class="rounded-xl border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ $t('hall.show.seating') }}</p><p class="font-semibold">{{ number_format($hall->seating_capacity) }}</p></li> @endif
                @if ($hall->floating_capacity) <li class="rounded-xl border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ $t('hall.show.standing') }}</p><p class="font-semibold">{{ number_format($hall->floating_capacity) }}</p></li> @endif
                <li class="rounded-xl border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ $t('hall.show.cooling') }}</p><p class="font-semibold">{{ $card['ac'] }}</p></li>
                @if ($hall->parking_cars !== null) <li class="rounded-xl border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ $t('hall.show.parking') }}</p><p class="font-semibold">{{ $t('hall.show.cars', ['n' => $hall->parking_cars]) }}</p></li> @endif
                @if ($hall->rooms) <li class="rounded-xl border border-slate-200 bg-white p-3"><p class="text-xs text-slate-500">{{ $t('hall.show.rooms') }}</p><p class="font-semibold">{{ $hall->rooms }}</p></li> @endif
            </ul>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
                <p class="font-semibold text-slate-500">{{ $t('hall.show.where') }}</p>
                <p class="mt-0.5 text-slate-900">{{ $hall->address_line }}
                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="ml-1 font-medium text-blue-700 underline">{{ $t('hall.show.directions') }}</a></p>
            </div>

            @if ($hall->about)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('hall.show.about') }}</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $hall->about }}</p>
                </section>
            @endif
            @if ($hall->amenities)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('hall.show.facilities') }}</h2>
                    <ul class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                        @foreach ($hall->amenities as $a) <li class="flex items-center gap-2"><span class="text-emerald-600" aria-hidden="true">&check;</span> {{ $a }}</li> @endforeach
                    </ul>
                </section>
            @endif
            @if ($hall->rules)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('hall.show.rules') }}</h2>
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $hall->rules }}</p>
                </section>
            @endif
        </div>

        <aside class="lg:col-span-1">
            <form method="GET" action="{{ route('customer.halls.book') }}" class="sticky top-20 space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="hall-slots">
                <input type="hidden" name="hall" value="{{ $hall->id }}">
                <h2 class="font-semibold">{{ $t('hall.show.slots') }}</h2>
                @foreach ($hallSlots as $s)
                    <label class="flex cursor-pointer items-start justify-between gap-3 rounded-xl border border-slate-200 p-3 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50" data-testid="hall-slot">
                        <span class="flex items-start gap-2">
                            <input type="radio" name="slot" value="{{ $s['slot']->id }}" @checked($loop->first) class="mt-1 h-4 w-4">
                            <span><span class="block font-medium">{{ $s['slot']->name }}</span><span class="block text-xs text-slate-500">{{ $s['slot']->timeLabel() }}</span></span>
                        </span>
                        <span class="shrink-0 font-semibold">{{ $s['price'] }}</span>
                    </label>
                @endforeach
                @if ($deposit) <p class="text-xs text-slate-500">{{ $t('hall.show.deposit', ['amount' => $deposit]) }}</p> @endif
                @if ($taxesKnown && $hallSlots->isNotEmpty())
                    <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.date') }}
                        <input type="date" name="date" min="{{ $first }}" max="{{ $last }}" value="{{ $first }}" required class="{{ $field }}">
                    </label>
                    <button type="submit" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="hall-continue">{{ $t('hall.show.check') }} →</button>
                    <p class="text-xs text-slate-500">{{ $t('hall.show.advance', ['percent' => $advance]) }}</p>
                @else
                    <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('hall.book.opening_soon') }}</p>
                @endif
            </form>
        </aside>
    </div>
</div>
