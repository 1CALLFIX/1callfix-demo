<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>

    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.hotels.home', ['city' => $city->slug, 'in' => $in, 'out' => $out]) }}" wire:navigate class="hover:underline">{{ $t('hotel.crumb') }}</a> ›
        <span>{{ $hotel->name }}</span>
    </nav>

    {{-- ── photos (owner 2026-11-14: every photo can be seen): arrows, counter, tiles, full screen; ♡ Like + Share ── --}}
    <div data-testid="hotel-gallery">
        <x-customer.photo-gallery class="mt-3" :photos="$photos" :title="$hotel->name" :item="'hotel:'.$hotel->id" :cover="$photos[0] ?? null" />
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
    <div class="mt-4 grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-5">
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
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.children') }}
            <input type="number" min="0" max="20" wire:model.live.debounce.500ms="children" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3">
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
                        <p class="text-sm text-slate-600">{{ $rc['room']->max_children ? $t('hotel.show.fits_children', ['adults' => $rc['room']->max_adults, 'children' => $rc['room']->max_children]) : $t('hotel.show.fits', ['adults' => $rc['room']->max_adults]) }}@if ($rc['room']->bed_configuration) · {{ $rc['room']->bed_configuration }}@endif</p>
                        <p class="mt-1 text-sm font-medium text-emerald-700">{{ $rc['meal'] }}</p>
                        @if ($rc['nr']) <p class="text-sm font-medium text-amber-700" data-testid="hotel-non-refundable">{{ $rc['nrLabel'] }}</p>
                        @elseif ($header['cancel']) <p class="text-sm font-medium text-emerald-700">✓ {{ $header['cancel'] }}</p> @endif
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
                            <a href="{{ route('customer.hotels.book', ['hotel' => $hotel->id, 'room' => $rc['room']->id, 'plan' => $rc['plan']->id, 'in' => $in, 'out' => $out, 'rooms' => $rc['quote']['rooms'], 'adults' => $adults, 'children' => $children ?: null, 'nr' => $rc['nr'] ? 1 : null]) }}"
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

    {{-- ── verified-stay reviews (Hotels H4) ── --}}
    @if ($reviewSummary['n'] > 0)
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4" data-testid="hotel-reviews">
            <h2 class="text-lg font-semibold">★ {{ $reviewSummary['avg'] }} · {{ \App\Support\SiteText::count('hotel.reviews', $reviewSummary['n']) }}</h2>
            <p class="text-xs text-slate-500">{{ $t('hotel.review.verified') }}</p>
            @if ($reviewSummary['scores'] !== [])
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    @foreach ($reviewSummary['scores'] as $k => $v)
                        <div class="rounded-lg bg-slate-50 px-2 py-1"><dt class="text-slate-600">{{ $t('hotel.review.score.'.$k) }}</dt><dd class="font-semibold">{{ $v }}/5</dd></div>
                    @endforeach
                </dl>
            @endif
            <ul class="mt-3 divide-y divide-slate-100">
                @foreach ($reviews as $rv)
                    <li class="py-3 text-sm">
                        <p><span class="font-semibold">{{ \Illuminate\Support\Str::before((string) $rv->customer?->name, ' ') ?: 'Guest' }}</span> <span class="text-amber-500">{{ str_repeat('★', (int) $rv->rating) }}</span> <span class="text-xs text-slate-500">{{ $rv->created_at->format('M Y') }}</span></p>
                        @if ($rv->comment) <p class="mt-1 text-slate-700">{{ $rv->comment }}</p> @endif
                        @if ($rv->provider_reply) <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-700"><span class="font-semibold">{{ $t('hotel.review.hotel_reply') }}:</span> {{ $rv->provider_reply }}</p> @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

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
