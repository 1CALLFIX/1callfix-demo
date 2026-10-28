@php
    $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2);
    $mapsLink = fn ($address) => $address ? 'https://www.google.com/maps/dir/?api=1&destination='.$address->lat.','.$address->lng : null;
@endphp

{{-- Poll for offers / job changes while online; the heartbeat below keeps the location fresh. --}}
<div @if ($rider->is_online) wire:poll.5s @endif
     x-data="{
        heartbeat: null,
        locate(done) {
            if (! navigator.geolocation) { done(null, null); return; }
            navigator.geolocation.getCurrentPosition(
                (p) => done(p.coords.latitude, p.coords.longitude),
                () => done(null, null),
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
            );
        },
        goOnline() { this.locate((lat, lng) => $wire.goOnline(lat, lng)); },
        startHeartbeat() {
            if (this.heartbeat) return;
            this.heartbeat = setInterval(() => this.locate((lat, lng) => { if (lat !== null) $wire.goOnline(lat, lng); }), 120000);
        },
        stopHeartbeat() { clearInterval(this.heartbeat); this.heartbeat = null; },
     }"
     x-init="@if ($rider->is_online) startHeartbeat() @endif"
     x-on:livewire:navigating.window="stopHeartbeat()">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight">Hi {{ \Illuminate\Support\Str::before($rider->user?->name ?? 'Rider', ' ') }}</h1>
            <p class="text-sm text-slate-600">{{ $rider->is_online ? 'You are online' : 'You are offline' }}</p>
        </div>
        @if ($rider->is_online)
            <button type="button" wire:click="goOffline" x-on:click="stopHeartbeat()"
                    class="inline-flex min-h-11 items-center gap-2 rounded-full bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700">
                <span class="h-2 w-2 rounded-full bg-white"></span> Online
            </button>
        @elseif ($rider->kyc_status === 'approved')
            <button type="button" x-on:click="goOnline(); startHeartbeat()"
                    class="inline-flex min-h-11 items-center gap-2 rounded-full bg-slate-800 px-5 text-sm font-semibold text-white hover:bg-slate-900">
                Go online
            </button>
        @endif
    </div>

    {{-- A rider who signed up on /partners can sign in before approval; offers start only once the documents are approved. --}}
    @if ($rider->kyc_status !== 'approved')
        <div role="status" @class(['mt-4 rounded-xl px-4 py-3 text-sm',
            'bg-red-50 text-red-800' => $rider->kyc_status === 'rejected',
            'bg-amber-50 text-amber-900' => $rider->kyc_status !== 'rejected'])>
            @if ($rider->kyc_status === 'rejected')
                Your documents were not approved. Our team will contact you about what to send again.
            @else
                Your documents are under review. You can go online and get parcel offers once they are approved.
            @endif
        </div>
    @endif

    @if ($notice)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ $error }}</div>
    @endif

    @unless ($parcelActive)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Parcel delivery is switched off in your area right now, so no new offers will come in.</div>
    @endunless
    @if ($rider->is_online && ! $locationFresh)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            We don't have a recent location for you, so you won't get offers.
            <button type="button" x-on:click="goOnline()" class="font-semibold underline">Share location</button>
        </div>
    @endif
    @if ($duesOwed > 0)
        <a href="{{ route('rider.earnings') }}" wire:navigate class="mt-4 block rounded-xl border p-3 text-sm {{ $overCashLimit ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-slate-200 bg-white text-slate-700' }}">
            Cash dues: <span class="font-semibold">{{ $fmt($duesOwed) }}</span>.
            {{ $overCashLimit ? 'You will not get cash orders until you settle.' : 'Recovered from your online earnings.' }} →
        </a>
    @endif

    {{-- ═══════════ Current job ═══════════ --}}
    @if ($job)
        <section class="mt-5 rounded-2xl border-2 border-blue-200 bg-white p-4" aria-labelledby="job-heading">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="job-heading" class="text-lg font-bold">Parcel {{ $job->code }}</h2>
                    <p class="text-sm text-slate-600">
                        {{ ucfirst($job->package_size) }}
                        @if ($job->package_weight_kg) · {{ rtrim(rtrim(number_format((float) $job->package_weight_kg, 2), '0'), '.') }} kg @endif
                        @if ($job->package_contents) · {{ $contents[$job->package_contents] ?? '' }} @endif
                        @if ($job->package_description) · {{ $job->package_description }} @endif
                    </p>
                    @if ($job->isScheduled())
                        <p class="mt-0.5 text-sm font-semibold text-blue-700">Pickup at {{ $job->scheduled_at->timezone($tz)->format('D j M, g:i A') }}</p>
                    @endif
                </div>
                <span class="text-lg font-semibold tabular-nums">{{ $fmt($job->price_quoted) }}</span>
            </div>

            @php $beforePickup = in_array($job->status, ['assigned', 'worker_en_route_pickup'], true); @endphp

            <div class="mt-4 space-y-3 text-sm">
                <div @class(['rounded-xl p-3', 'bg-blue-50' => $beforePickup, 'bg-slate-50 opacity-70' => ! $beforePickup])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pickup</p>
                    <p class="font-medium">{{ $job->pickupAddress?->address_line }}</p>
                    <p class="text-slate-600">{{ $job->pickup_contact_name ?: $job->customer?->name }} · {{ $job->pickup_contact_phone ?: $job->customer?->phone }}</p>
                    @if ($beforePickup)
                        <div class="mt-2 flex flex-wrap gap-2">
                            <a href="{{ $mapsLink($job->pickupAddress) }}" target="_blank" rel="noopener" class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</a>
                            @if ($job->pickup_contact_phone ?: $job->customer?->phone)
                                <a href="tel:{{ $job->pickup_contact_phone ?: $job->customer?->phone }}" class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call</a>
                            @endif
                        </div>
                    @endif
                </div>
                <div @class(['rounded-xl p-3', 'bg-emerald-50' => ! $beforePickup, 'bg-slate-50' => $beforePickup])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Drop</p>
                    <p class="font-medium">{{ $job->dropoffAddress?->address_line }}</p>
                    <p class="text-slate-600">{{ $job->recipient_name }} · {{ $job->recipient_phone }}</p>
                    @unless ($beforePickup)
                        <div class="mt-2 flex flex-wrap gap-2">
                            <a href="{{ $mapsLink($job->dropoffAddress) }}" target="_blank" rel="noopener" class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</a>
                            @if ($job->recipient_phone)
                                <a href="tel:{{ $job->recipient_phone }}" class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call recipient</a>
                            @endif
                        </div>
                    @endunless
                </div>
                @if ($job->customer_note)
                    <p class="rounded-xl bg-slate-50 p-3 text-slate-700"><span class="font-medium">Note:</span> {{ $job->customer_note }}</p>
                @endif
            </div>

            @if ($beforePickup)
                @if ($job->status === 'assigned')
                    <button type="button" wire:click="onTheWay({{ $job->id }})" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-blue-300 bg-white text-sm font-semibold text-blue-700 hover:bg-blue-50">I'm on my way to pickup</button>
                @endif

                @if (! $job->arrived_at_pickup_at)
                    <button type="button" x-on:click="locate((lat, lng) => $wire.arrived({{ $job->id }}, lat, lng))"
                            class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white text-sm font-semibold text-slate-800 hover:bg-slate-50">I've arrived at pickup</button>
                @else
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
                        Arrived {{ $job->arrived_at_pickup_at->timezone($tz)->format('g:i A') }} · waiting {{ $waiting['minutes'] }} min.
                        @if ($waiting['charge'] > 0)
                            <span class="block font-semibold text-amber-900">Collect {{ $fmt($waiting['charge']) }} waiting charge from the sender. It is yours.</span>
                        @endif
                    </p>
                @endif

                @if ($job->payment_method === 'cash' && ! $job->collectsCashAtDelivery() && $job->payment_status !== 'paid')
                    <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">Collect {{ $fmt($job->price_quoted) }} cash from the sender before you confirm pickup.</p>
                @elseif ($job->collectsCashAtDelivery())
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">Nothing to collect at pickup — the recipient pays {{ $fmt($job->price_quoted) }} cash at delivery.</p>
                @endif

                <form wire:submit="confirmPickup({{ $job->id }})" class="mt-4">
                    <label for="pickup-otp" class="block text-sm font-medium">Pickup code from the sender</label>
                    <div class="mt-1 flex gap-2">
                        <input id="pickup-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" wire:model="pickupOtp" required
                               class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-lg tracking-widest focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                        <button type="submit" class="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">Picked up</button>
                    </div>
                </form>
            @else
                @if ($job->collectsCashAtDelivery() && $job->payment_status !== 'paid')
                    <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">Collect {{ $fmt($job->price_quoted) }} cash from the recipient before you enter the delivery code.</p>
                @endif
                <form wire:submit="confirmDelivery({{ $job->id }})" class="mt-4">
                    <label for="delivery-otp" class="block text-sm font-medium">Delivery code from the recipient</label>
                    <div class="mt-1 flex gap-2">
                        <input id="delivery-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" wire:model="deliveryOtp" required
                               class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-lg tracking-widest focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                        <button type="submit" class="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700">Delivered</button>
                    </div>
                </form>
                @if ($job->collectsCashAtDelivery() && $job->payment_status !== 'paid')
                    {{-- Recipient won't pay: the sender pays the rider on the spot (UPI or cash), then deliver as normal. --}}
                    <div class="mt-4 rounded-xl border border-slate-200 p-3 text-sm">
                        <p class="font-medium text-slate-800">Recipient not paying?</p>
                        <p class="mt-0.5 text-slate-600">Call the sender. They pay you {{ $fmt($job->price_quoted) }} now by UPI or cash, then deliver as normal.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($job->pickup_contact_phone ?: $job->customer?->phone)
                                <a href="tel:{{ $job->pickup_contact_phone ?: $job->customer?->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call sender</a>
                            @endif
                            <button type="button" wire:click="senderPaid({{ $job->id }})" wire:confirm="Did the sender pay you {{ $fmt($job->price_quoted) }}?"
                                    class="inline-flex min-h-10 items-center rounded-lg bg-slate-800 px-3 font-semibold text-white hover:bg-slate-900">Sender paid me</button>
                        </div>
                    </div>
                @endif
            @endif
        </section>
    @endif

    {{-- ═══════════ Offers ═══════════ --}}
    @if (! $job && $rider->is_online && $parcelActive)
        <section class="mt-5" aria-labelledby="offers-heading">
            <h2 id="offers-heading" class="text-base font-semibold">Offers</h2>
            @forelse ($offers as $attempt)
                @php $offer = $attempt->dispatchable; @endphp
                <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-4" wire:key="offer-{{ $attempt->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ ucfirst($offer->package_size) }} parcel · {{ $offer->code }}</p>
                            <p class="text-sm text-slate-600">
                                @if ($attempt->distance_km !== null){{ rtrim(rtrim(number_format((float) $attempt->distance_km, 1), '0'), '.') }} km to pickup · @endif
                                @if ($offer->distance_km){{ rtrim(rtrim(number_format((float) $offer->distance_km, 1), '0'), '.') }} km trip @endif
                            </p>
                        </div>
                        <span class="text-lg font-semibold tabular-nums text-emerald-700">{{ $fmt($offer->price_quoted) }}</span>
                    </div>
                    <p class="mt-2 truncate text-sm text-slate-700"><span class="font-medium">From:</span> {{ $offer->pickupAddress?->address_line }}</p>
                    <p class="truncate text-sm text-slate-700"><span class="font-medium">To:</span> {{ $offer->dropoffAddress?->address_line }}</p>
                    @if ($offer->isScheduled() || $offer->package_contents)
                        <p class="mt-1 text-xs text-slate-600">
                            @if ($offer->isScheduled())<span class="font-semibold text-blue-700">Pickup at {{ $offer->scheduled_at->timezone($tz)->format('g:i A') }}</span>@endif
                            @if ($offer->isScheduled() && $offer->package_contents) · @endif
                            @if ($offer->package_contents){{ $contents[$offer->package_contents] ?? '' }}@endif
                        </p>
                    @endif
                    @if ($offer->collectsCashAtDelivery())
                        <p class="mt-1 text-xs font-semibold text-amber-800">Cash order — recipient pays {{ $fmt($offer->price_quoted) }} at delivery</p>
                    @elseif ($offer->payment_method === 'cash')
                        <p class="mt-1 text-xs font-semibold text-amber-800">Cash order — collect {{ $fmt($offer->price_quoted) }} at pickup</p>
                    @endif
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="decline({{ $offer->id }})" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 text-sm font-semibold text-slate-700 hover:bg-slate-50">Decline</button>
                        <button type="button" wire:click="accept({{ $offer->id }})" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-blue-600 text-sm font-semibold text-white hover:bg-blue-700">Accept</button>
                    </div>
                </div>
            @empty
                <p class="mt-3 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">Waiting for parcel offers near you…</p>
            @endforelse
        </section>
    @elseif (! $job && ! $rider->is_online && $rider->kyc_status === 'approved')
        <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">Go online to start getting parcel offers.</p>
    @endif
</div>
