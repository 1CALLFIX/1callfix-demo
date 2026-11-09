@php
    $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2);
    $mapsLink = fn ($address) => $address ? 'https://www.google.com/maps/dir/?api=1&destination='.$address->lat.','.$address->lng : null;
@endphp

{{-- Poll for offers / job changes while online; the heartbeat below keeps the location fresh. --}}
<div @if ($rider->is_online) wire:poll.5s @endif data-on-job="{{ ($job || $shopJob || $returnJob) ? '1' : '0' }}"
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
            // Stage 2: every 30 s while carrying a parcel (the sender sees how far away the rider is), else every 2 min.
            let last = Date.now();
            this.heartbeat = setInterval(() => {
                if (Date.now() - last < (this.$root.dataset.onJob === '1' ? 25000 : 115000)) return;
                last = Date.now();
                this.locate((lat, lng) => { if (lat !== null) $wire.goOnline(lat, lng); });
            }, 30000);
        },
        stopHeartbeat() { clearInterval(this.heartbeat); this.heartbeat = null; },
     }"
     x-init="@if ($rider->is_online) startHeartbeat() @endif"
     x-on:livewire:navigating.window="stopHeartbeat()">
    <style>[x-cloak]{display:none!important}</style>

    {{-- Partner apps 2026-11-04: one big online switch (the delivery-app way), then today's numbers at a glance. --}}
    <div @class(['flex items-center justify-between gap-3 rounded-2xl p-4',
        'bg-emerald-600 text-white' => $rider->is_online, 'bg-slate-800 text-white' => ! $rider->is_online])>
        <div class="min-w-0">
            <p class="text-sm font-medium opacity-90">Hi {{ \Illuminate\Support\Str::before($rider->user?->name ?? 'Rider', ' ') }}</p>
            <h1 class="text-xl font-bold tracking-tight">{{ $rider->is_online ? 'You are online' : 'You are offline' }}</h1>
            @if ($job || $shopJob || $returnJob)
                <p class="text-xs opacity-90">{{ $rider->is_online ? 'You stay online until this delivery is done.' : 'You have a delivery. Turn on the switch so the customer can follow you.' }}</p>
            @else
                <p class="text-xs opacity-90">{{ $rider->is_online ? 'Offers will ring on this phone.' : ($rider->kyc_status === 'approved' ? 'Turn on the switch to get delivery offers.' : 'Waiting for your documents to be approved.') }}</p>
            @endif
        </div>
        @if ($rider->is_online && ($job || $shopJob || $returnJob))
            {{-- Owner 2026-11-09: locked online until the delivery in hand is done. --}}
            <button type="button" disabled role="switch" aria-checked="true" aria-disabled="true" aria-label="Online until your delivery is done"
                    class="relative inline-flex h-10 w-[4.5rem] shrink-0 cursor-not-allowed items-center rounded-full bg-white/30 opacity-70 ring-2 ring-white/70" data-testid="online-switch-locked">
                <span class="absolute right-1 grid h-8 w-8 place-items-center rounded-full bg-white shadow">
                    <svg class="h-4 w-4 text-emerald-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                </span>
            </button>
        @elseif ($rider->is_online)
            <button type="button" wire:click="goOffline" x-on:click="stopHeartbeat()" role="switch" aria-checked="true" aria-label="Go offline"
                    class="relative inline-flex h-10 w-[4.5rem] shrink-0 items-center rounded-full bg-white/30 ring-2 ring-white/70" data-testid="online-switch">
                <span class="absolute right-1 h-8 w-8 rounded-full bg-white shadow"></span>
            </button>
        @elseif ($rider->kyc_status === 'approved')
            <button type="button" x-on:click="goOnline(); startHeartbeat()" role="switch" aria-checked="false" aria-label="Go online"
                    class="relative inline-flex h-10 w-[4.5rem] shrink-0 items-center rounded-full bg-white/20 ring-2 ring-white/40" data-testid="online-switch">
                <span class="absolute left-1 h-8 w-8 rounded-full bg-slate-300 shadow"></span>
            </button>
        @endif
    </div>

    <div class="mt-3 grid grid-cols-3 divide-x divide-slate-100 rounded-2xl border border-slate-200 bg-white text-center" data-testid="rider-today">
        <div class="px-2 py-3">
            <p class="text-lg font-bold tabular-nums">{{ $fmt($today['earned']) }}</p>
            <p class="text-xs text-slate-500">Earned today</p>
        </div>
        <div class="px-2 py-3">
            <p class="text-lg font-bold tabular-nums">{{ $today['jobs'] }}</p>
            <p class="text-xs text-slate-500">Jobs today</p>
        </div>
        <a href="{{ route('rider.earnings') }}" wire:navigate @class(['px-2 py-3', 'rounded-r-2xl bg-rose-50' => $overCashLimit])>
            <p @class(['text-lg font-bold tabular-nums', 'text-rose-700' => $duesOwed > 0])>{{ $fmt($duesOwed) }}</p>
            <p class="text-xs text-slate-500">Cash dues →</p>
        </a>
    </div>
    @if ($overCashLimit)
        <p class="mt-2 text-sm font-medium text-rose-800">You will not get cash orders until you settle your cash dues.</p>
    @endif

    {{-- A rider who signed up on /partners can sign in before approval; offers start only once the documents are approved. --}}
    @if ($rider->kyc_status !== 'approved')
        <div role="status" @class(['mt-4 rounded-xl px-4 py-3 text-sm',
            'bg-red-50 text-red-800' => $rider->kyc_status === 'rejected',
            'bg-amber-50 text-amber-900' => $rider->kyc_status !== 'rejected'])>
            @if ($rider->kyc_status === 'rejected')
                Your documents were not approved. Our team will contact you about what to send again.
            @else
                Your documents are under review. You can go online and get delivery offers once they are approved.
            @endif
        </div>
    @endif

    @if ($notice)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ $error }}</div>
    @endif

    @unless ($anyDeliveryOn)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Deliveries are switched off in your area right now, so no new offers will come in.</div>
    @endunless
    @if ($rider->is_online && ! $locationFresh)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            We don't have a recent location for you, so you won't get offers.
            <button type="button" x-on:click="goOnline()" class="font-semibold underline">Share location</button>
        </div>
    @endif

    {{-- ═══════════ Current job ═══════════ --}}
    @if ($job)
        <section class="mt-5 rounded-2xl border-2 border-blue-400 bg-white p-4 shadow-sm" aria-labelledby="job-heading" data-testid="current-job">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="job-heading" class="flex flex-wrap items-center gap-2 text-lg font-bold"><x-rider.kind kind="parcel" size="md" /> {{ $job->code }}</h2>
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
                @php
                    // Stage 2: drop 1 is the order's own recipient; extra drop points follow in order.
                    $drops = collect([['key' => 'main', 'n' => 1, 'address' => $job->dropoffAddress, 'name' => $job->recipient_name, 'phone' => $job->recipient_phone, 'done' => $job->drop_delivered_at !== null]])
                        ->concat($job->stops->map(fn ($st) => ['key' => $st->id, 'n' => $st->sequence, 'address' => $st->address, 'name' => $st->recipient_name, 'phone' => $st->recipient_phone, 'done' => $st->delivered_at !== null]));
                    $multi = $drops->count() > 1;
                    $returning = $job->isReturning();
                @endphp
                @foreach ($drops as $drop)
                    @php $current = ! $beforePickup && ! $returning && ! $drop['done']; @endphp
                    <div wire:key="drop-{{ $drop['key'] }}" @class(['rounded-xl p-3', 'bg-emerald-50' => $current, 'bg-slate-50' => ! $current, 'opacity-70' => $drop['done']])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $multi ? 'Drop '.$drop['n'] : 'Drop' }}{{ $drop['done'] ? ' · done' : '' }}</p>
                        <p class="font-medium">{{ $drop['address']?->address_line }}</p>
                        <p class="text-slate-600">{{ $drop['name'] }} · {{ $drop['phone'] }}</p>
                        @if ($current)
                            <div class="mt-2 flex flex-wrap gap-2">
                                <a href="{{ $mapsLink($drop['address']) }}" target="_blank" rel="noopener" class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</a>
                                @if ($drop['phone'])
                                    <a href="tel:{{ $drop['phone'] }}" class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call recipient</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
                @if ($job->return_trip)
                    <div @class(['rounded-xl p-3', 'bg-amber-50' => $returning, 'bg-slate-50' => ! $returning])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Return to sender</p>
                        <p class="font-medium">{{ $job->pickupAddress?->address_line }}</p>
                        <p class="text-slate-600">After the last drop, bring the return back here. The sender gives you a return code.</p>
                    </div>
                @endif
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
            @elseif ($returning)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p class="font-semibold">Every drop is done. Take the return back to the sender.</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <a href="{{ $mapsLink($job->pickupAddress) }}" target="_blank" rel="noopener" class="inline-flex min-h-9 items-center rounded-lg border border-amber-300 bg-white px-3 font-medium">Directions</a>
                        @if ($job->pickup_contact_phone ?: $job->customer?->phone)
                            <a href="tel:{{ $job->pickup_contact_phone ?: $job->customer?->phone }}" class="inline-flex min-h-9 items-center rounded-lg border border-amber-300 bg-white px-3 font-medium">Call sender</a>
                        @endif
                    </div>
                </div>
                <form wire:submit="confirmReturn({{ $job->id }})" class="mt-4">
                    <label for="return-otp" class="block text-sm font-medium">Return code from the sender</label>
                    <div class="mt-1 flex gap-2">
                        <input id="return-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" wire:model="returnOtp" required
                               class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-lg tracking-widest focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                        <button type="submit" class="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700">Returned</button>
                    </div>
                </form>
            @else
                @if ($job->collectsCashAtDelivery() && $job->payment_status !== 'paid')
                    <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">Collect {{ $fmt($job->price_quoted) }} cash from the recipient before you enter the delivery code.</p>
                @endif
                {{-- Stage 2: cash on delivery for the sender (a shop). The rider owes it to HQ; the sender is paid from it. --}}
                @if ($job->isCod() && ! $job->cod_collected_at)
                    <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">Collect {{ $fmt($job->cod_amount) }} item price (cash on delivery) from the recipient before you hand it over. It is added to your cash dues.</p>
                @endif
                @unless ($job->drop_delivered_at)
                    <form wire:submit="confirmDelivery({{ $job->id }})" class="mt-4">
                        <label for="delivery-otp" class="block text-sm font-medium">{{ $multi ? 'Drop 1: code from '.($job->recipient_name ?: 'the recipient') : 'Delivery code from the recipient' }}</label>
                        <div class="mt-1 flex gap-2">
                            <input id="delivery-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" wire:model="deliveryOtp" required
                                   class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-lg tracking-widest focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                            <button type="submit" class="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700">Delivered</button>
                        </div>
                    </form>
                @endunless
                @foreach ($job->stops->whereNull('delivered_at') as $stop)
                    <form wire:submit="confirmStop({{ $job->id }}, {{ $stop->id }})" class="mt-4" wire:key="stop-form-{{ $stop->id }}">
                        <label for="stop-otp-{{ $stop->id }}" class="block text-sm font-medium">Drop {{ $stop->sequence }}: code from {{ $stop->recipient_name ?: 'the recipient' }}</label>
                        <div class="mt-1 flex gap-2">
                            <input id="stop-otp-{{ $stop->id }}" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" wire:model="stopOtps.{{ $stop->id }}" required
                                   class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-lg tracking-widest focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                            <button type="submit" class="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700">Delivered</button>
                        </div>
                    </form>
                @endforeach
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

            {{-- Pickup safety (owner decision 2026-10-29): no photo on every parcel — only when something looks
                 unusual. The sender is asked to let the rider photograph the item before packing and the parcel
                 after packing; if they refuse, or the parcel holds a banned item, the rider refuses it here. --}}
            <div x-data="{ open: {{ $errors->hasAny(['safetyPhoto', 'safetyConcerns', 'safetyStage', 'safetyNote', 'refuseReason', 'refuseNote']) ? 'true' : 'false' }} }" class="mt-5 border-t border-slate-200 pt-4">
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="safety-panel"
                        class="flex w-full items-center justify-between gap-3 text-left text-sm font-semibold text-slate-800">
                    <span>Something not right?</span>
                    <span class="text-xs font-medium text-slate-500">
                        @if ($safetyPhotoCount > 0){{ $safetyPhotoCount }} {{ $safetyPhotoCount === 1 ? 'photo' : 'photos' }} saved · @endif
                        <span x-text="open ? 'Close' : 'Open'">Open</span>
                    </span>
                </button>

                <div id="safety-panel" x-show="open" x-cloak class="mt-3 space-y-4 text-sm">
                    <p class="text-slate-600">If the sender, the place, the item, the packing or the pickup time looks unusual, take a photo. Ask the sender to show you the item before packing, then photograph the parcel after packing. Only the 1CallFix team sees the photos.</p>

                    <div class="rounded-xl border border-slate-200 p-3">
                        <p class="font-medium text-slate-800">Take a safety photo</p>
                        <fieldset class="mt-2">
                            <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">What looks unusual?</legend>
                            <div class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1">
                                @foreach ($safetyConcernOptions as $value => $label)
                                    <label class="flex min-h-9 items-center gap-2">
                                        <input type="checkbox" value="{{ $value }}" wire:model="safetyConcerns" class="h-4 w-4 rounded border-slate-300 text-blue-600">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('safetyConcerns') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </fieldset>
                        <fieldset class="mt-3">
                            <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">Photo of</legend>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                @foreach ($safetyStageOptions as $value => $label)
                                    <label class="flex min-h-9 items-center gap-2">
                                        <input type="radio" value="{{ $value }}" wire:model="safetyStage" class="h-4 w-4 border-slate-300 text-blue-600">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <label for="safety-photo" class="mt-3 block text-xs font-semibold uppercase tracking-wide text-slate-500">Photo</label>
                        <input id="safety-photo" type="file" accept="image/*" capture="environment" wire:model="safetyPhoto"
                               class="mt-1 block w-full text-sm file:mr-3 file:min-h-10 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:text-sm file:font-semibold file:text-white">
                        <x-ui.image-size use="safety_photo" />
                        <p wire:loading wire:target="safetyPhoto" class="mt-1 text-xs text-slate-500">Uploading…</p>
                        @error('safetyPhoto') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        <label for="safety-note" class="mt-3 block text-xs font-semibold uppercase tracking-wide text-slate-500">Note (optional)</label>
                        <input id="safety-note" type="text" maxlength="300" wire:model="safetyNote"
                               class="mt-1 block min-h-10 w-full rounded-lg border border-slate-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                        <button type="button" wire:loading.attr="disabled" wire:target="safetyPhoto,saveSafetyPhoto"
                                x-on:click="locate((lat, lng) => $wire.saveSafetyPhoto({{ $job->id }}, lat, lng))"
                                class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-800 text-sm font-semibold text-white hover:bg-slate-900 disabled:opacity-60">Save photo</button>
                    </div>

                    @if ($beforePickup)
                        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3">
                            <p class="font-medium text-rose-900">Refuse this parcel</p>
                            <p class="mt-0.5 text-rose-900/80">Don't argue with the sender. Refuse here, leave safely, and our team follows up.@if ($refusalPay > 0) You get {{ $fmt($refusalPay) }} for the trip.@endif</p>
                            <fieldset class="mt-2 space-y-1">
                                <legend class="sr-only">Why are you refusing it?</legend>
                                @foreach ($refusalReasons as $value => $reason)
                                    <label class="flex min-h-9 items-start gap-2">
                                        <input type="radio" value="{{ $value }}" wire:model="refuseReason" class="mt-1 h-4 w-4 border-rose-300 text-rose-600">
                                        <span class="text-rose-950">{{ $reason['label'] }}</span>
                                    </label>
                                @endforeach
                            </fieldset>
                            @error('refuseReason') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            <label for="refuse-note" class="mt-2 block text-xs font-semibold uppercase tracking-wide text-rose-900/70">What happened (needed for "something else")</label>
                            <input id="refuse-note" type="text" maxlength="300" wire:model="refuseNote"
                                   class="mt-1 block min-h-10 w-full rounded-lg border border-rose-300 bg-white px-3 text-sm focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-500/30">
                            @error('refuseNote') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            <button type="button" wire:loading.attr="disabled" wire:target="refuseParcel"
                                    x-on:click="if (confirm(@js('Refuse parcel '.$job->code.'? The order will be cancelled and the sender told.'))) locate((lat, lng) => $wire.refuseParcel({{ $job->id }}, lat, lng))"
                                    class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-rose-600 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-60">Refuse parcel</button>
                        </div>
                    @else
                        <p class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-rose-900">Already picked up and something is wrong? Stop, don't open the parcel any further, and call support.</p>
                    @endif
                </div>
            </div>

            {{-- Stage 2: chat with the sender. --}}
            <div class="mt-4">
                <livewire:parcel.chat-box :order-id="$job->id" :key="'chat-'.$job->id" />
            </div>
        </section>
    @endif

    {{-- Rider app 2026-11-04: a shop / food delivery or a return pickup runs right here too. --}}
    @if (! $job && $shopJob)
        <div class="mt-5">@include('livewire.rider.partials.shop-job', ['job' => $shopJob, 'otpModel' => 'shopOtp', 'deliverAction' => 'deliverShop'])</div>
    @elseif (! $job && $returnJob)
        <div class="mt-5">@include('livewire.rider.partials.return-job', ['otpModel' => 'shopOtp', 'collectAction' => 'collectShopReturn', 'dropAction' => 'dropShopReturn'])</div>
    @endif

    {{-- ═══════════ Offers: every kind together, each with its label ═══════════ --}}
    @if (! $job && ! $shopJob && ! $returnJob && $rider->is_online)
        <section class="mt-5 space-y-3" aria-labelledby="offers-heading">
            <h2 id="offers-heading" class="text-base font-semibold">Offers</h2>
            @include('livewire.rider.partials.shop-offers', ['acceptAction' => 'acceptShopOffer', 'declineAction' => 'declineShopOffer', 'acceptPickupAction' => 'acceptReturnPickup'])
            @foreach ($offers as $attempt)
                @php $offer = $attempt->dispatchable; @endphp
                <div class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm" wire:key="offer-{{ $attempt->id }}" data-testid="offer-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <x-rider.kind kind="parcel" />
                            <p class="mt-2 font-semibold">{{ ucfirst($offer->package_size) }} parcel · {{ $offer->code }}</p>
                            <p class="text-sm text-slate-600">
                                @if ($attempt->distance_km !== null){{ rtrim(rtrim(number_format((float) $attempt->distance_km, 1), '0'), '.') }} km to pickup · @endif
                                @if ($offer->distance_km){{ rtrim(rtrim(number_format((float) $offer->distance_km, 1), '0'), '.') }} km trip @endif
                            </p>
                        </div>
                        <span class="text-lg font-semibold tabular-nums text-emerald-700">{{ $fmt($offer->price_quoted) }}</span>
                    </div>
                    <p class="mt-2 truncate text-sm text-slate-700"><span class="font-medium">From:</span> {{ $offer->pickupAddress?->address_line }}</p>
                    <p class="truncate text-sm text-slate-700"><span class="font-medium">To:</span> {{ $offer->dropoffAddress?->address_line }}</p>
                    @if ($offer->stops->isNotEmpty() || $offer->return_trip)
                        <p class="mt-1 text-xs font-semibold text-blue-800">
                            {{ collect([$offer->stops->isNotEmpty() ? ($offer->stops->count() + 1).' drop points' : null, $offer->return_trip ? 'Return to sender after the drop' : null])->filter()->implode(' · ') }}
                        </p>
                    @endif
                    @if ($offer->isScheduled() || $offer->package_contents)
                        <p class="mt-1 text-xs text-slate-600">
                            @if ($offer->isScheduled())<span class="font-semibold text-blue-700">Pickup at {{ $offer->scheduled_at->timezone($tz)->format('g:i A') }}</span>@endif
                            @if ($offer->isScheduled() && $offer->package_contents) · @endif
                            @if ($offer->package_contents){{ $contents[$offer->package_contents] ?? '' }}@endif
                        </p>
                    @endif
                    @if ($offer->isCod())
                        <p class="mt-1 text-xs font-semibold text-amber-800">Cash on delivery — collect {{ $fmt($offer->cod_amount) }} item price at the drop</p>
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
            @endforeach
            @if ($offers->isEmpty() && $shopOffers->isEmpty() && $pickups->isEmpty())
                <p class="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">Waiting for delivery offers near you… Parcel, shop and food jobs all come here.</p>
            @endif
        </section>
    @elseif (! $job && ! $shopJob && ! $returnJob && ! $rider->is_online && $rider->kyc_status === 'approved')
        <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">Go online to start getting delivery offers.</p>
    @endif
</div>
