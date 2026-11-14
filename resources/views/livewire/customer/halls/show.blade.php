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

    {{-- Owner 2026-11-14: every photo can be seen — swipe or arrows on the big photo, all photos as tiles below, tap
         any tile for the full-screen view. --}}
    <div class="mt-3" x-data="{ i: 0, n: {{ max(1, count($photos)) }}, open: false,
            go(k) { this.i = (k + this.n) % this.n; this.$refs.strip?.children[this.i]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' }); } }"
         @keydown.escape.window="open = false" data-testid="hall-gallery">
        <div class="relative">
            <ul x-ref="strip" class="flex snap-x snap-mandatory overflow-x-auto rounded-2xl [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="{{ $t('hall.show.photos') }}"
                @scroll.debounce.100ms="i = Math.round($el.scrollLeft / $el.clientWidth)">
                @forelse ($photos as $idx => $p)
                    <li class="w-full shrink-0 snap-start">
                        <button type="button" class="block w-full" @click="i = {{ $idx }}; open = true">
                            <img src="{{ $p }}" alt="{{ $hall->name }} {{ $idx + 1 }}" class="aspect-[3/2] w-full rounded-2xl object-cover sm:aspect-[16/7]" @if ($idx > 0) loading="lazy" @endif>
                        </button>
                    </li>
                @empty
                    <li class="aspect-[3/2] w-full rounded-2xl bg-slate-100"></li>
                @endforelse
            </ul>
            <x-customer.item-actions class="absolute right-3 top-3" :item="'hall:'.$hall->id" :title="$hall->name" :image="$card['cover']" />
            @if (count($photos) > 1)
                <button type="button" @click="go(i - 1)" class="absolute left-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-2xl shadow" aria-label="Previous photo">‹</button>
                <button type="button" @click="go(i + 1)" class="absolute right-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-2xl shadow" aria-label="Next photo">›</button>
                <span class="absolute bottom-3 left-3 rounded-full bg-black/60 px-3 py-1 text-xs font-semibold text-white"><span x-text="i + 1">1</span> / {{ count($photos) }}</span>
                <button type="button" @click="open = true" class="absolute bottom-3 right-3 min-h-11 rounded-full bg-white/90 px-4 text-sm font-semibold shadow">{{ $t('hall.show.all_photos', ['n' => count($photos)]) }}</button>
            @endif
        </div>
        @if (count($photos) > 1)
            <ul class="mt-2 grid grid-cols-5 gap-2 sm:grid-cols-8" data-testid="hall-thumbs">
                @foreach ($photos as $idx => $p)
                    <li><button type="button" @click="go({{ $idx }})" :class="i === {{ $idx }} ? 'ring-2 ring-blue-600 ring-offset-2' : 'opacity-80 hover:opacity-100'" class="block w-full overflow-hidden rounded-lg">
                        <img src="{{ $p }}" alt="" loading="lazy" class="aspect-square w-full object-cover"></button></li>
                @endforeach
            </ul>
        @endif
        @if ($photos !== [])
            <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto bg-black/90 p-4" role="dialog" aria-modal="true" aria-label="{{ $t('hall.show.photos') }}">
                <div class="mx-auto max-w-4xl">
                    <div class="sticky top-0 flex justify-end py-2"><button type="button" @click="open = false" class="min-h-11 rounded-full bg-white px-5 font-semibold">{{ $t('hall.show.close') }} ✕</button></div>
                    <ul class="space-y-3">
                        @foreach ($photos as $idx => $p)
                            <li><img src="{{ $p }}" alt="{{ $hall->name }} {{ $idx + 1 }}" loading="lazy" class="w-full rounded-xl"></li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
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
            @if ($facilityGroups !== [])
                <section data-testid="hall-facilities">
                    <h2 class="text-lg font-semibold">{{ $t('hall.show.facilities') }}</h2>
                    @php $tones = ['bg-sky-50 text-sky-900', 'bg-pink-50 text-pink-900', 'bg-amber-50 text-amber-900', 'bg-emerald-50 text-emerald-900', 'bg-violet-50 text-violet-900', 'bg-teal-50 text-teal-900']; @endphp
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach ($facilityGroups as $gi => $g)
                            <div class="rounded-xl p-3 {{ $tones[$gi % count($tones)] }}">
                                @if ($g['title'] !== '') <p class="text-sm font-semibold">{{ $g['title'] }}</p> @endif
                                <ul class="mt-1.5 space-y-1.5 text-sm text-slate-800">
                                    @foreach ($g['items'] as $f)
                                        <li class="flex items-start justify-between gap-2">
                                            <span class="flex items-start gap-2"><span class="text-emerald-600" aria-hidden="true">&check;</span> {{ $f['name'] }}</span>
                                            @if ($f['paid'])
                                                <span class="shrink-0 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $t('hall.show.paid').($f['price'] ? ' · '.$f['price'] : '') }}</span>
                                            @else
                                                <span class="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">{{ $t('hall.show.free') }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($outside->isNotEmpty())
                <section data-testid="hall-outside">
                    <h2 class="text-lg font-semibold">{{ $t('hall.show.outside') }}</h2>
                    <ul class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                        @foreach ($outside as $name => $answer)
                            <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                <span>{{ $name }}</span>
                                <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold text-white', 'bg-emerald-600' => $answer === 'yes', 'bg-amber-500' => $answer === 'fee', 'bg-red-600' => $answer === 'no'])>{{ $t('hall.show.outside_'.$answer) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($hall->rule_list || $hall->rules)
                <section data-testid="hall-rules">
                    <h2 class="text-lg font-semibold">{{ $t('hall.show.rules') }}</h2>
                    @if ($hall->rule_list)
                        <ul class="mt-2 space-y-1.5 text-sm text-slate-800">
                            @foreach ($hall->rule_list as $r) <li class="flex items-start gap-2"><span class="text-rose-600" aria-hidden="true">•</span> {{ $r }}</li> @endforeach
                        </ul>
                    @endif
                    @if ($hall->rules) <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $hall->rules }}</p> @endif
                </section>
            @endif
            @if ($taxesKnown && $hallSlots->isNotEmpty())
                <livewire:customer.halls.availability :hall-id="$hall->id" :city-slug="$city->slug" :key="'avail-'.$hall->id" />
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
                    @if ($maxDays > 1) <p class="text-xs font-medium text-blue-800" data-testid="hall-days-hint">{{ $t('hall.show.days_hint', ['n' => $maxDays]) }}</p> @endif
                @else
                    <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('hall.book.opening_soon') }}</p>
                @endif
            </form>
        </aside>
    </div>
</div>
