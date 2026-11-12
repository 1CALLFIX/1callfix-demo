<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>

    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.hotels.home', ['city' => $city->slug, 'in' => $in, 'out' => $out]) }}" wire:navigate class="hover:underline">{{ $t('hotel.crumb') }}</a> ›
        <span>{{ $hotel->name }}</span>
    </nav>

    {{-- ── photos: swipe on a phone; ♡ Like + Share on the first ── --}}
    <div class="relative mt-3 overflow-hidden rounded-2xl bg-slate-100" data-testid="hotel-gallery">
        <div class="flex snap-x snap-mandatory overflow-x-auto">
            @forelse ($photos as $i => $p)
                <img src="{{ $p }}" alt="{{ $hotel->name }} photo {{ $i + 1 }}" class="aspect-[3/2] w-full shrink-0 snap-center object-cover sm:w-2/3" @if ($i > 0) loading="lazy" @endif>
            @empty
                <div class="aspect-[3/2] w-full"></div>
            @endforelse
        </div>
        <x-customer.item-actions class="absolute right-3 top-3" :item="'hotel:'.$hotel->id" :title="$hotel->name" :image="$photos[0] ?? null" />
        @if (count($photos) > 1)
            <span class="absolute bottom-3 right-3 rounded-full bg-slate-900/70 px-2 py-0.5 text-xs font-semibold text-white">{{ count($photos) }} 📷</span>
        @endif
    </div>

    <div class="mt-4 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $hotel->name }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $header['type'] }} · {{ $hotel->address_line }}</p>
            <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="mt-1 inline-block text-sm font-medium text-blue-600 underline">{{ $t('hotel.show.directions') }}</a>
        </div>
        @if ($header['rating'])
            <span class="shrink-0 rounded-lg bg-emerald-600 px-2 py-1 text-sm font-bold text-white">★ {{ $t('hotel.card.rating', ['avg' => $header['rating']['avg'], 'n' => $header['rating']['n']]) }}</span>
        @endif
    </div>

    {{-- ── stay dates (same fields as the list) ── --}}
    <div class="mt-4 grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-4">
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.check_in') }}
            <input type="date" wire:model.live="checkIn" value="{{ $in }}" min="{{ now()->toDateString() }}" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3">
        </label>
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.check_out') }}
            <input type="date" wire:model.live="checkOut" value="{{ $out }}" min="{{ $in }}" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3">
        </label>
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.rooms') }}
            <input type="number" min="1" wire:model.live.debounce.500ms="rooms" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3">
        </label>
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.guests') }}
            <input type="number" min="1" wire:model.live.debounce.500ms="adults" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3">
        </label>
    </div>

    {{-- ── rooms ── --}}
    <h2 class="mt-6 text-lg font-semibold">{{ $t('hotel.show.rooms') }}</h2>
    <ul class="mt-3 space-y-4" data-testid="hotel-rooms">
        @forelse ($roomCards as $rc)
            <li class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                @if ($rc['photos'] !== [])
                    <div class="flex gap-1 overflow-x-auto">
                        @foreach ($rc['photos'] as $p) <img src="{{ $p }}" alt="{{ $rc['room']->name }}" class="h-36 w-56 shrink-0 object-cover" loading="lazy"> @endforeach
                    </div>
                @endif
                <div class="grid gap-4 p-4 sm:grid-cols-[1fr_auto]">
                    <div>
                        <h3 class="text-base font-semibold">{{ $rc['room']->name }}</h3>
                        <p class="text-sm text-slate-600">{{ $t('hotel.show.fits', ['adults' => $rc['room']->max_adults]) }}@if ($rc['room']->bed_configuration) · {{ $rc['room']->bed_configuration }}@endif</p>
                        <p class="mt-1 text-sm font-medium text-emerald-700">{{ $rc['meal'] }}</p>
                        @if ($header['cancel']) <p class="text-sm font-medium text-emerald-700">✓ {{ $header['cancel'] }}</p> @endif
                        @if ($rc['room']->description) <p class="mt-2 text-sm text-slate-600">{{ $rc['room']->description }}</p> @endif
                    </div>
                    <div class="min-w-[14rem] rounded-xl bg-slate-50 p-3 text-sm">
                        <p class="text-right"><span class="text-xl font-bold">{{ $rc['perNight'] }}</span> <span class="text-xs text-slate-500">{{ $rc['quote']['taxes_known'] ? $t('hotel.card.per_night') : $t('hotel.card.plus_taxes') }}</span></p>
                        <dl class="mt-2 space-y-1">
                            @foreach ($rc['lines'] as [$label, $amount])
                                <div class="flex justify-between gap-3 text-slate-600"><dt>{{ $label }}</dt><dd>{{ $amount }}</dd></div>
                            @endforeach
                            <div class="flex justify-between gap-3 border-t border-slate-200 pt-1 font-semibold"><dt>{{ $t('hotel.show.total') }}</dt><dd>{{ $rc['total'] }}</dd></div>
                        </dl>
                        @if ($rc['left']) <p class="mt-1 text-right text-xs font-semibold text-red-600">{{ $rc['left'] }}</p> @endif
                        @if ($rc['bookable'])
                            <a href="{{ route('customer.hotels.book', ['hotel' => $hotel->id, 'room' => $rc['room']->id, 'plan' => $rc['plan']->id, 'in' => $in, 'out' => $out, 'rooms' => $rc['quote']['rooms'], 'adults' => $adults]) }}"
                               class="mt-3 flex min-h-11 items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="hotel-book">{{ $t('hotel.show.book') }}</a>
                        @elseif (! $rc['quote']['available'])
                            <p class="mt-3 text-center text-sm font-semibold text-slate-500">{{ $t('hotel.card.full') }}</p>
                        @elseif (! $rc['quote']['taxes_known'])
                            <p class="mt-3 text-center text-sm font-semibold text-slate-500">{{ $t('hotel.show.booking_soon') }}</p>
                        @endif
                    </div>
                </div>
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">{{ $t('hotel.empty') }}</li>
        @endforelse
    </ul>

    {{-- ── about ── --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        @if ($hotel->description)
            <section class="rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase text-slate-500">{{ $t('hotel.show.about') }}</h2>
                <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $hotel->description }}</p>
                <p class="mt-3 text-sm font-medium text-slate-800">{{ $times }}</p>
            </section>
        @endif
        @if (! empty($hotel->facilities))
            <section class="rounded-2xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase text-slate-500">{{ $t('hotel.show.facilities') }}</h2>
                <ul class="mt-2 grid grid-cols-2 gap-1 text-sm text-slate-700">
                    @foreach ($hotel->facilities as $f) <li>✓ {{ $f }}</li> @endforeach
                </ul>
            </section>
        @endif
        @if ($hotel->policies)
            <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:col-span-2">
                <h2 class="text-sm font-semibold uppercase text-slate-500">{{ $t('hotel.show.rules') }}</h2>
                <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $hotel->policies }}</p>
            </section>
        @endif
    </div>
</div>
