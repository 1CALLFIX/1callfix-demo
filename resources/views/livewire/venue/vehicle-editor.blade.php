<?php
    // Vehicles for rent (owner 2026-11-18, OLX-style): five numbered, coloured steps; taps from Rental Settings lists for
    // this area; the owner types only the make and model, the price, a landmark and the pickup address. One Save bar.
    $inp = 'mt-1.5 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-base focus:outline focus:outline-2 focus:outline-blue-600';
    $chip = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border-2 px-4 text-sm font-medium transition';
    $step = fn ($colour) => 'grid h-9 w-9 shrink-0 place-items-center rounded-full text-base font-bold text-white '.$colour;
    $err = fn ($f) => $errors->has($f) ? '<p class="mt-1.5 text-sm text-red-700">'.e($errors->first($f)).'</p>' : '';
?>
<div class="space-y-5 pb-24">
    <a href="{{ route('venue.dashboard') }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">&larr; Home</a>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold tracking-tight">{{ $vehicle ? $vehicle->title : 'Add your vehicle for rent' }}</h1>
        @if ($vehicle)
            <span @class(['rounded-full px-3 py-1 text-sm font-semibold',
                'bg-emerald-100 text-emerald-800' => $vehicle->status === 'approved', 'bg-amber-100 text-amber-800' => $vehicle->status === 'in_review',
                'bg-red-100 text-red-700' => $vehicle->status === 'rejected', 'bg-slate-100 text-slate-700' => $vehicle->status === 'draft']) data-testid="vehicle-status">{{ $vehicle->statusLabel() }}</span>
        @endif
    </div>

    @if ($vehicle?->status === 'rejected' && $vehicle->rejection_reason)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please change this and send it again</p>
            <p class="mt-1">{{ $vehicle->rejection_reason }}</p>
        </div>
    @elseif ($vehicle?->status === 'in_review')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">⏳ We are checking your vehicle. You can change it again once we reply, usually within a day.</div>
    @elseif ($vehicle?->status === 'approved')
        <div class="space-y-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" data-testid="vehicle-live-box">
            @if (! $vehicle->is_active)
                <p>⏸️ Hidden: customers do not see it. Tap "Available again" when it is free.</p>
            @elseif ($vehicle->expires_at && $vehicle->expires_at->isPast())
                <p>⏰ Hidden since {{ $vehicle->expires_at->format('j M Y') }}. Is it still available? Tap "Available again".</p>
            @else
                <p>✅ Customers see your vehicle{{ $vehicle->expires_at ? ' until '.$vehicle->expires_at->format('j M Y') : '' }}. Prices and details change at once; new photos, description or address show after we check them.</p>
            @endif
            <div class="flex flex-wrap gap-2">
                @if ($vehicle->is_active && $vehicle->isLive())
                    <button type="button" wire:click="notAvailable" wire:confirm="Hide this vehicle for now? Customers will stop seeing it." class="min-h-11 rounded-xl border border-emerald-300 bg-white px-4 font-semibold text-emerald-800" data-testid="vehicle-not-available">⏸️ Not available now</button>
                @else
                    <button type="button" wire:click="availableAgain" class="min-h-11 rounded-xl bg-emerald-600 px-4 font-semibold text-white" data-testid="vehicle-available">Available again</button>
                @endif
            </div>
        </div>
        @if (($fee['on'] ?? false) || ($fee['featured'] ?? 0) > 0)
            <div class="space-y-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900" data-testid="vehicle-fee-box">
                @if ($fee['on'])
                    <p class="font-semibold">
                        @if ($vehicle->expires_at && $vehicle->expires_at->isFuture())
                            Your listing runs until {{ $vehicle->expires_at->format('j M Y') }}. Add {{ $fee['listingDays'] }} more days for {{ $symbol }}{{ number_format($fee['listing']) }}.
                        @else
                            Show your vehicle to customers for {{ $fee['listingDays'] }} days: {{ $symbol }}{{ number_format($fee['listing']) }}.
                        @endif
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @if ($fee['online'])
                            <button type="button" wire:click="payFee('listing', 'online')" wire:loading.attr="disabled" class="min-h-11 rounded-xl bg-indigo-600 px-4 font-semibold text-white" data-testid="fee-listing-online">Pay online</button>
                        @endif
                        <button type="button" wire:click="payFee('listing', 'wallet')" wire:loading.attr="disabled" class="min-h-11 rounded-xl border border-indigo-300 bg-white px-4 font-semibold text-indigo-800" data-testid="fee-listing-wallet">Pay from wallet ({{ $symbol }}{{ number_format($fee['wallet']) }})</button>
                    </div>
                @endif
                @if ($fee['featured'] > 0 && $vehicle->isLive())
                    <p class="{{ $fee['on'] ? 'border-t border-indigo-200 pt-3 ' : '' }}font-semibold">
                        @if ($vehicle->is_featured && $vehicle->featured_until && $vehicle->featured_until->isFuture())
                            Shown first until {{ $vehicle->featured_until->format('j M Y') }}. Add {{ $fee['featuredDays'] }} more days for {{ $symbol }}{{ number_format($fee['featured']) }}.
                        @else
                            Show it first on the lists for {{ $fee['featuredDays'] }} days: {{ $symbol }}{{ number_format($fee['featured']) }}. Optional.
                        @endif
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @if ($fee['online'])
                            <button type="button" wire:click="payFee('featured', 'online')" wire:loading.attr="disabled" class="min-h-11 rounded-xl bg-amber-500 px-4 font-semibold text-white" data-testid="fee-featured-online">Pay online</button>
                        @endif
                        <button type="button" wire:click="payFee('featured', 'wallet')" wire:loading.attr="disabled" class="min-h-11 rounded-xl border border-amber-300 bg-white px-4 font-semibold text-amber-800" data-testid="fee-featured-wallet">Pay from wallet</button>
                    </div>
                @endif
                @if ($fee['wallet'] < max($fee['listing'], $fee['featured']))
                    <p class="text-xs">Wallet low? <a class="font-semibold underline" href="{{ route('customer.earnings.wallet') }}" target="_blank" rel="noopener">Add money to your wallet</a> (one minute), then come back.</p>
                @endif
            </div>
            @if ($fee['online'])
                @assets
                <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                @endassets
                @script
                <script>
                    $wire.on('razorpay-open', (e) => {
                        const o = e.order ?? e[0]?.order;
                        if (!o || !window.Razorpay) return;
                        new window.Razorpay({
                            key: o.razorpay_key_id ?? o.key_id,
                            order_id: o.razorpay_order_id,
                            amount: o.amount,
                            currency: o.currency,
                            name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                            prefill: @js(\App\Support\RazorpayPrefill::forCurrentUser()),
                            description: 'Vehicle listing fee',
                            handler: () => window.location.reload(),
                        }).open();
                    });
                </script>
                @endscript
            @endif
        @endif
        @if ($vehicle->pending_changes)
            <div role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p>⏳ Your change ({{ collect(array_keys($vehicle->pending_changes))->map(fn ($f) => ['about' => 'description', 'address_line' => 'pickup address', 'landmark' => 'landmark', 'lat' => 'location', 'lng' => 'location', 'cover_image' => 'main photo', 'photos' => 'photos'][$f] ?? $f)->unique()->implode(', ') }}) is waiting for our check.</p>
                <button type="button" wire:click="withdrawChanges" wire:confirm="Withdraw the waiting change?" class="text-sm font-semibold underline">Withdraw</button>
            </div>
        @elseif ($vehicle->pending_rejection)
            <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">Your last change was not accepted: {{ $vehicle->pending_rejection }}</div>
        @endif
    @endif

    @error('vehicle') <div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="vehicle-error">{{ $message }}</div> @enderror
    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="vehicle-notice">{{ $notice }}</div> @endif

    <fieldset @disabled(! $editable) class="space-y-5">
        {{-- 1. The vehicle --}}
        <section class="space-y-4 rounded-2xl border-2 border-blue-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-blue-600') }}">1</span><h2 class="text-lg font-semibold">Your vehicle</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">What kind of vehicle?</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Kind of vehicle" data-testid="vehicle-kinds">
                    @foreach ($kinds as $t)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="kind" value="{{ $t }}" class="sr-only"> {{ $t }}
                        </label>
                    @endforeach
                </div>
                {!! $err('kind') !!}
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="vh-make" class="block text-sm font-medium text-slate-900">Company <span class="font-normal text-slate-500">(e.g. Maruti, Honda, Mahindra)</span></label>
                    <input id="vh-make" type="text" wire:model="make" maxlength="60" class="{{ $inp }}">
                    {!! $err('make') !!}
                </div>
                <div>
                    <label for="vh-model" class="block text-sm font-medium text-slate-900">Model <span class="font-normal text-slate-500">(e.g. Swift, Activa, Bolero)</span></label>
                    <input id="vh-model" type="text" wire:model="model" maxlength="60" class="{{ $inp }}">
                    {!! $err('model') !!}
                </div>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">Fuel</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Fuel">
                    @foreach ($fuels as $t)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="fuel" value="{{ $t }}" class="sr-only"> {{ $t }}
                        </label>
                    @endforeach
                </div>
                {!! $err('fuel') !!}
            </div>
            @if ($gears !== [])
                <div>
                    <p class="text-sm font-medium text-slate-900">Gears <span class="font-normal text-slate-500">(optional)</span></p>
                    <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Gears">
                        @foreach ($gears as $t)
                            <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                                <input type="radio" wire:model="transmission" value="{{ $t }}" class="sr-only"> {{ $t }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div>
                <label for="vh-seats" class="block text-sm font-medium text-slate-900">Seats <span class="font-normal text-slate-500">(tap or type, optional)</span></label>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($seatChoices as $n)
                        <button type="button" wire:click="pickSeats({{ $n }})" @class(['min-h-11 rounded-full border-2 px-4 text-sm font-semibold', 'border-blue-600 bg-blue-600 text-white' => $seats === (string) $n, 'border-blue-200 bg-blue-50 text-blue-800' => $seats !== (string) $n])>{{ $n }}</button>
                    @endforeach
                </div>
                <input id="vh-seats" type="number" inputmode="numeric" min="1" max="80" wire:model.live="seats" class="{{ $inp }} max-w-[8rem]">
                {!! $err('seats') !!}
            </div>
            <div>
                <label for="vh-year" class="block text-sm font-medium text-slate-900">Year <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="vh-year" type="number" inputmode="numeric" min="1980" max="{{ now()->year + 1 }}" wire:model="year" placeholder="{{ now()->year - 3 }}" class="{{ $inp }} max-w-[8rem]">
                {!! $err('year') !!}
            </div>
        </section>

        {{-- 2. Price --}}
        <section class="space-y-4 rounded-2xl border-2 border-emerald-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-emerald-600') }}">2</span><h2 class="text-lg font-semibold">Price</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">How do you rent it?</p>
                <div class="mt-2 flex flex-wrap gap-2" data-testid="vehicle-driver-chips">
                    @foreach ($driverChoices as $t)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-600 has-[:checked]:text-white">
                            <input type="checkbox" wire:model="driverOptions" value="{{ $t }}" class="sr-only"> {{ $t }}
                        </label>
                    @endforeach
                </div>
                {!! $err('driverOptions') !!}
            </div>
            <div>
                <label for="vh-day" class="block text-sm font-medium text-slate-900">Price per day ({{ $symbol }})</label>
                <input id="vh-day" type="number" inputmode="numeric" min="0" wire:model="priceDay" class="{{ $inp }} max-w-xs text-lg font-semibold" data-testid="vehicle-price-day">
                {!! $err('priceDay') !!}
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label for="vh-hour" class="block text-sm font-medium text-slate-900">Per hour ({{ $symbol }}) <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="vh-hour" type="number" inputmode="numeric" min="0" wire:model="priceHour" class="{{ $inp }}">
                    {!! $err('priceHour') !!}
                </div>
                <div>
                    <label for="vh-deposit" class="block text-sm font-medium text-slate-900">Deposit ({{ $symbol }}) <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="vh-deposit" type="number" inputmode="numeric" min="0" wire:model="deposit" class="{{ $inp }}">
                    {!! $err('deposit') !!}
                </div>
                <div>
                    <label for="vh-km" class="block text-sm font-medium text-slate-900">Free km per day <span class="font-normal text-slate-500">(optional, empty = no limit)</span></label>
                    <input id="vh-km" type="number" inputmode="numeric" min="0" wire:model="kmPerDay" class="{{ $inp }}">
                    {!! $err('kmPerDay') !!}
                </div>
            </div>
        </section>

        {{-- 3. Where --}}
        <section class="space-y-4 rounded-2xl border-2 border-amber-100 bg-white p-4 sm:p-6" x-data="{ busy: false, err: '' }">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-amber-500') }}">3</span><h2 class="text-lg font-semibold">Where is it?</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Area</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Area">
                    @foreach ($zones as $z)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-500 has-[:checked]:text-white">
                            <input type="radio" wire:model.live="zoneId" value="{{ $z->id }}" class="sr-only"> {{ $z->name }}
                        </label>
                    @endforeach
                </div>
                {!! $err('zoneId') !!}
            </div>
            <div>
                <label for="vh-landmark" class="block text-sm font-medium text-slate-900">Landmark <span class="font-normal text-slate-500">(everyone sees this, e.g. "Near RTC bus stand")</span></label>
                <input id="vh-landmark" type="text" wire:model="landmark" maxlength="160" class="{{ $inp }}">
                {!! $err('landmark') !!}
            </div>
            <div>
                <label for="vh-addr" class="block text-sm font-medium text-slate-900">Pickup address <span class="font-normal text-slate-500">(only for our team; customers see the area and call you)</span></label>
                <input id="vh-addr" type="text" wire:model="addressLine" placeholder="Door no, street, area" class="{{ $inp }}">
                {!! $err('addressLine') !!}
            </div>
            <div>
                <button type="button" x-bind:disabled="busy" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 font-semibold text-amber-900 hover:border-amber-500" data-testid="vehicle-locate"
                    x-on:click="busy = true; err = ''; navigator.geolocation ? navigator.geolocation.getCurrentPosition(
                        (p) => { $wire.set('lat', String(p.coords.latitude)); $wire.set('lng', String(p.coords.longitude)); busy = false },
                        () => { err = 'Location is off. Allow location for this app and try again.'; busy = false },
                        { enableHighAccuracy: true, timeout: 15000 }) : (err = 'This phone cannot share its location.', busy = false)">
                    📍 <span x-show="busy">Finding you…</span><span x-show="! busy">{{ $lat !== '' ? 'Location saved ✓ (tap to update)' : 'Use my current location' }}</span>
                </button>
                <p class="mt-1 text-xs text-slate-500">Stand where the vehicle is parked and tap.</p>
                <p x-show="err" x-text="err" class="mt-1.5 text-sm text-red-700"></p>
            </div>
        </section>

        {{-- 4. Features and rules --}}
        <section class="space-y-4 rounded-2xl border-2 border-teal-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-teal-600') }}">4</span><h2 class="text-lg font-semibold">Features and rules</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Tap what your vehicle has <span class="font-normal text-slate-500">(optional)</span></p>
                <div class="mt-2 flex flex-wrap gap-2" data-testid="vehicle-feature-chips">
                    @foreach ($featureChoices as $f)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-600 has-[:checked]:text-white">
                            <input type="checkbox" wire:model="features" value="{{ $f }}" class="sr-only"> {{ $f }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">Your rules <span class="font-normal text-slate-500">(tap the ones you follow)</span></p>
                <div class="mt-2 flex flex-wrap gap-2" data-testid="vehicle-rule-chips">
                    @foreach ($ruleChoices as $r)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-rose-600 has-[:checked]:bg-rose-600 has-[:checked]:text-white">
                            <input type="checkbox" wire:model="ruleList" value="{{ $r }}" class="sr-only"> {{ $r }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <label for="vh-about" class="block text-sm font-medium text-slate-900">About the vehicle <span class="font-normal text-slate-500">(a few lines, optional)</span></label>
                <textarea id="vh-about" wire:model="about" rows="3" placeholder="Well kept, serviced every 3 months. Good for family trips and airport drops." class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-base"></textarea>
                {!! $err('about') !!}
            </div>
        </section>

        {{-- 5. Photos --}}
        <section class="space-y-4 rounded-2xl border-2 border-fuchsia-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-fuchsia-600') }}">5</span><h2 class="text-lg font-semibold">Photos</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Main photo</p>
                <?php $coverUrl = \App\Models\VehicleListing::imageUrl($vehicle?->pending_changes['cover_image'] ?? $vehicle?->cover_image); ?>
                @if ($coverUrl) <img src="{{ $coverUrl }}" alt="Vehicle cover" class="mt-2 aspect-[3/2] w-full max-w-md rounded-2xl object-cover"> @endif
                <label class="mt-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-fuchsia-300 bg-fuchsia-50 px-4 text-sm font-semibold text-fuchsia-800">
                    📷 {{ $coverUrl ? 'Change main photo' : 'Add main photo' }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" wire:model="cover" aria-label="Main photo" class="sr-only">
                </label>
                <x-ui.image-size use="vehicle_photo" />
                <p wire:loading wire:target="cover" class="mt-1 text-sm text-slate-500">Uploading…</p>
                {!! $err('cover') !!}
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">More photos <span class="font-normal text-slate-500">(front, side, back, inside{{ $minPhotos > 0 ? ': at least '.$minPhotos : '' }})</span></p>
                @if ($photos !== [])
                    <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach ($photos as $i => $p)
                            <li class="relative" wire:key="vphoto-{{ $i }}-{{ md5($p) }}">
                                <img src="{{ \App\Models\VehicleListing::imageUrl($p) }}" alt="Vehicle photo {{ $i + 1 }}" class="aspect-square w-full rounded-xl object-cover">
                                <button type="button" wire:click="removePhoto({{ $i }})" class="absolute right-1 top-1 grid h-9 w-9 place-items-center rounded-full bg-white/90 text-red-700 shadow" aria-label="Remove photo {{ $i + 1 }}">&times;</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <label class="mt-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-fuchsia-300 bg-fuchsia-50 px-4 text-sm font-semibold text-fuchsia-800">
                    🖼️ Add photos
                    <input type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="newPhotos" aria-label="Add photos" class="sr-only">
                </label>
                <x-ui.image-size use="vehicle_photo" />
                <p wire:loading wire:target="newPhotos" class="mt-1 text-sm text-slate-500">Uploading…</p>
                {!! $err('newPhotos') !!}
                {!! $err('newPhotos.*') !!}
            </div>
        </section>
    </fieldset>

    @if ($editable)
        <div class="fixed inset-x-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur" style="bottom: calc(4rem + env(safe-area-inset-bottom, 0px));">
            <div class="mx-auto flex max-w-5xl items-center gap-2">
                @if ($vehicle === null || $vehicle->isEditable())
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="vehicle-send-for-review">Send for review ✓</button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800">Save</button>
                @else
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="vehicle-save">Save changes</button>
                @endif
            </div>
        </div>
    @endif
</div>
