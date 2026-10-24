{{-- Send a parcel (owner decision 2026-10-23). Every number on this page comes from ParcelPricing /
     ParcelSettings server-side; the JS below only turns a map search or a GPS fix into setPickupPin() /
     setDropPin() calls. --}}
@php
    $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2);
    $hasPlaces = (bool) config('services.google_maps.key');
    $inputClass = 'mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30';
    $labelClass = 'block text-sm font-medium text-slate-700';
@endphp

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold tracking-tight">Send a parcel</h1>
    <p class="mt-1 text-sm text-slate-600">A rider picks it up and delivers it the same trip. Pay online, from your wallet, or cash at pickup.</p>

    @if ($browsingZoneKnown && ! $activeHere)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            Parcel delivery may not be available in the area you are browsing. Choose your pickup point below and we'll tell you if we pick up from there.
        </div>
    @endif

    @if ($error)
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ $error }}</div>
    @endif

    {{-- One tap: drop point + recipient of a recent parcel. With the default pickup, Book is the next tap. --}}
    @if ($recentRecipients->isNotEmpty())
        <div class="mt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Send again</p>
            <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
                @foreach ($recentRecipients as $recent)
                    @php $chosen = $recipientPhone === $recent->recipient_phone && $dropLine === $recent->dropoffAddress?->address_line; @endphp
                    <button type="button" wire:click="useRecent({{ $recent->id }})"
                            @class(['shrink-0 rounded-xl border px-3 py-2 text-left text-sm transition', 'border-blue-600 bg-blue-50 ring-1 ring-blue-600' => $chosen, 'border-slate-200 bg-white hover:border-slate-300' => ! $chosen])>
                        <span class="block font-semibold text-slate-900">{{ $recent->recipient_name ?: $recent->recipient_phone }}</span>
                        <span class="block max-w-[11rem] truncate text-xs text-slate-500">{{ $recent->dropoffAddress?->address_line }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <form wire:submit="submit" class="mt-5 space-y-5 pb-4">
        {{-- ═══════════ 1. Pickup ═══════════ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" aria-labelledby="pickup-heading">
            <h2 id="pickup-heading" class="flex items-center gap-2 text-base font-semibold">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">1</span>
                Pickup from
            </h2>

            @if ($savedAddresses->isNotEmpty())
                <div class="mt-3 flex gap-2 text-sm" role="tablist">
                    <button type="button" wire:click="$set('pickupMode', 'saved')" @class(['rounded-full px-3 py-1.5 font-medium', 'bg-blue-600 text-white' => $pickupMode === 'saved', 'bg-slate-100 text-slate-700' => $pickupMode !== 'saved'])>Saved address</button>
                    <button type="button" wire:click="$set('pickupMode', 'pin')" @class(['rounded-full px-3 py-1.5 font-medium', 'bg-blue-600 text-white' => $pickupMode === 'pin', 'bg-slate-100 text-slate-700' => $pickupMode !== 'pin'])>Another place</button>
                </div>
            @endif

            @if ($pickupMode === 'saved' && $savedAddresses->isNotEmpty())
                <label for="pickup-saved" class="sr-only">Saved pickup address</label>
                <select id="pickup-saved" wire:model.live="pickupAddressId" class="{{ $inputClass }}">
                    @foreach ($savedAddresses as $address)
                        <option value="{{ $address->id }}">{{ $address->label }} — {{ \Illuminate\Support\Str::limit($address->address_line, 60) }}</option>
                    @endforeach
                </select>
            @endif

            <div @class(['mt-3 space-y-3', 'hidden' => $pickupMode !== 'pin'])>
                <div wire:ignore data-parcel-places="pickup" @if (! $hasPlaces) hidden @endif>
                    <label for="pickup-search" class="{{ $labelClass }}">Search for the pickup place</label>
                    <div class="relative">
                        <input id="pickup-search" type="text" autocomplete="off" data-places-input
                               placeholder="Building, street or area" class="{{ $inputClass }}" role="combobox" aria-expanded="false" aria-autocomplete="list">
                        <ul data-places-results hidden role="listbox" class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg"></ul>
                    </div>
                    <p data-places-error hidden class="mt-1 text-xs text-rose-700"></p>
                </div>

                <button type="button" data-parcel-locate hidden
                        class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <x-icon name="map-pin" class="h-4 w-4" /> <span data-parcel-locate-label>Use my current location</span>
                </button>

                @if ($pickupLat !== null)
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700"><span class="font-medium">Pin:</span> {{ $pickupLine }}</p>
                @elseif (! $hasPlaces)
                    <p class="text-sm text-slate-600">Use your current location, or save the address first in <a href="{{ route('customer.addresses') }}" wire:navigate class="font-medium text-blue-700 underline">My addresses</a>.</p>
                @endif

                <div>
                    <label for="pickup-detail" class="{{ $labelClass }}">House / flat / floor</label>
                    <input id="pickup-detail" type="text" wire:model.blur="pickupDetail" maxlength="150" class="{{ $inputClass }}">
                </div>
            </div>

            @if ($pickupZoneActive === false)
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">We don't pick up parcels from this location yet.</p>
            @endif

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="pickup-contact-name" class="{{ $labelClass }}">Contact at pickup</label>
                    <input id="pickup-contact-name" type="text" wire:model.blur="pickupContactName" maxlength="120" class="{{ $inputClass }}">
                </div>
                <div>
                    <label for="pickup-contact-phone" class="{{ $labelClass }}">Their phone</label>
                    <input id="pickup-contact-phone" type="tel" inputmode="tel" wire:model.blur="pickupContactPhone" class="{{ $inputClass }}">
                    @error('pickupContactPhone') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- ═══════════ 2. Drop ═══════════ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" aria-labelledby="drop-heading">
            <h2 id="drop-heading" class="flex items-center gap-2 text-base font-semibold">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">2</span>
                Deliver to
            </h2>

            @if ($savedAddresses->isNotEmpty())
                <div class="mt-3 flex gap-2 text-sm" role="tablist">
                    <button type="button" wire:click="$set('dropMode', 'pin')" @class(['rounded-full px-3 py-1.5 font-medium', 'bg-blue-600 text-white' => $dropMode === 'pin', 'bg-slate-100 text-slate-700' => $dropMode !== 'pin'])>Search a place</button>
                    <button type="button" wire:click="$set('dropMode', 'saved')" @class(['rounded-full px-3 py-1.5 font-medium', 'bg-blue-600 text-white' => $dropMode === 'saved', 'bg-slate-100 text-slate-700' => $dropMode !== 'saved'])>One of my addresses</button>
                </div>
            @endif

            @if ($dropMode === 'saved' && $savedAddresses->isNotEmpty())
                <label for="drop-saved" class="sr-only">Saved drop address</label>
                <select id="drop-saved" wire:model.live="dropAddressId" class="{{ $inputClass }}">
                    <option value="">Choose an address</option>
                    @foreach ($savedAddresses as $address)
                        <option value="{{ $address->id }}">{{ $address->label }} — {{ \Illuminate\Support\Str::limit($address->address_line, 60) }}</option>
                    @endforeach
                </select>
            @endif

            <div @class(['mt-3 space-y-3', 'hidden' => $dropMode !== 'pin'])>
                <div wire:ignore data-parcel-places="drop" @if (! $hasPlaces) hidden @endif>
                    <label for="drop-search" class="{{ $labelClass }}">Search for the drop place</label>
                    <div class="relative">
                        <input id="drop-search" type="text" autocomplete="off" data-places-input
                               placeholder="Building, street or area" class="{{ $inputClass }}" role="combobox" aria-expanded="false" aria-autocomplete="list">
                        <ul data-places-results hidden role="listbox" class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg"></ul>
                    </div>
                    <p data-places-error hidden class="mt-1 text-xs text-rose-700"></p>
                </div>

                @if ($dropLat !== null)
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700"><span class="font-medium">Pin:</span> {{ $dropLine }}</p>
                @elseif (! $hasPlaces)
                    <p class="text-sm text-slate-600">Map search is not available right now. Choose one of your saved addresses instead.</p>
                @endif

                <div>
                    <label for="drop-detail" class="{{ $labelClass }}">House / flat / floor</label>
                    <input id="drop-detail" type="text" wire:model.blur="dropDetail" maxlength="150" class="{{ $inputClass }}">
                </div>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="recipient-name" class="{{ $labelClass }}">Recipient's name <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="recipient-name" type="text" wire:model.blur="recipientName" maxlength="120" class="{{ $inputClass }}">
                    @error('recipientName') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="recipient-phone" class="{{ $labelClass }}">Recipient's phone <span class="text-rose-600">*</span></label>
                    <input id="recipient-phone" type="tel" inputmode="tel" wire:model.blur="recipientPhone" required class="{{ $inputClass }}">
                    @error('recipientPhone') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>
            </div>
            {{-- Android Chrome: name + number from the phone book in one tap. Hidden where unsupported. --}}
            <button type="button" data-parcel-contacts hidden
                    class="mt-2 inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                <x-icon name="users" class="h-4 w-4" /> Choose from contacts
            </button>
            <p class="mt-2 text-xs text-slate-500">The recipient gives the rider a delivery code. You'll see it on your order, and we text it to them when SMS is on.</p>
        </section>

        {{-- ═══════════ 3. Package ═══════════ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" aria-labelledby="package-heading">
            <h2 id="package-heading" class="flex items-center gap-2 text-base font-semibold">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">3</span>
                What are you sending?
            </h2>

            <fieldset class="mt-3">
                <legend class="{{ $labelClass }}">Size</legend>
                <div class="mt-1 grid grid-cols-3 gap-2">
                    @foreach ($sizes as $value => $label)
                        <label @class([
                            'flex cursor-pointer flex-col items-center rounded-xl border p-3 text-center text-sm transition',
                            'border-blue-600 bg-blue-50 text-blue-800 ring-1 ring-blue-600' => $packageSize === $value,
                            'border-slate-200 text-slate-700 hover:border-slate-300' => $packageSize !== $value,
                        ])>
                            <input type="radio" wire:model.live="packageSize" value="{{ $value }}" class="sr-only">
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="mt-0.5 text-xs text-slate-500">{{ ['small' => 'Envelope / hand', 'medium' => 'Shoebox / bag', 'large' => 'Two hands'][$value] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <details class="mt-3 rounded-lg border border-slate-200 px-3 py-2" @if ($packageWeightKg !== '' || $packageDescription !== '' || $customerNote !== '') open @endif>
            <summary class="cursor-pointer text-sm font-medium text-slate-700">More details <span class="font-normal text-slate-400">(optional: weight, contents, note)</span></summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="weight" class="{{ $labelClass }}">Approx. weight (kg)</label>
                    <input id="weight" type="number" step="0.1" min="0" inputmode="decimal" wire:model.live.debounce.500ms="packageWeightKg" class="{{ $inputClass }}">
                    @if ($maxWeight)<p class="mt-1 text-xs text-slate-500">Up to {{ rtrim(rtrim(number_format($maxWeight, 2), '0'), '.') }} kg.</p>@endif
                    @error('packageWeightKg') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="description" class="{{ $labelClass }}">What's inside</label>
                    <input id="description" type="text" wire:model.blur="packageDescription" maxlength="255" placeholder="e.g. documents, keys, lunch box" class="{{ $inputClass }}">
                </div>
            </div>
            <div class="mt-3">
                <label for="note" class="{{ $labelClass }}">Note for the rider</label>
                <textarea id="note" rows="2" wire:model.blur="customerNote" maxlength="500" class="{{ $inputClass }}"></textarea>
            </div>
            </details>
            <p class="mt-2 text-xs text-slate-500">No cash, valuables, liquids that can spill, or anything illegal.</p>
        </section>

        {{-- ═══════════ 4. Price & pay ═══════════ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" aria-labelledby="pay-heading" aria-live="polite">
            <h2 id="pay-heading" class="flex items-center gap-2 text-base font-semibold">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">4</span>
                Price & payment
            </h2>

            @if ($quote)
                <dl class="mt-3 space-y-1.5 text-sm">
                    @foreach ($quote['lines'] as $line)
                        <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ $line['label'] }}</dt><dd class="tabular-nums">{{ $fmt($line['amount']) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 text-base font-semibold"><dt>Total ({{ rtrim(rtrim(number_format($quote['distance_km'], 1), '0'), '.') }} km)</dt><dd class="tabular-nums">{{ $fmt($quote['total']) }}</dd></div>
                </dl>
            @elseif ($quoteError)
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $quoteError }}</p>
            @else
                <p class="mt-3 text-sm text-slate-600">Choose the pickup and drop points to see the price.</p>
            @endif

            @if ($methods)
                <fieldset class="mt-4">
                    <legend class="{{ $labelClass }}">Pay with</legend>
                    <div class="mt-1 space-y-2">
                        @foreach ($methods as $value => $label)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-3 py-2.5 text-sm hover:bg-slate-50">
                                <input type="radio" wire:model.live="paymentMethod" value="{{ $value }}" class="text-blue-600 focus:ring-blue-500">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- Pay at: Pickup | Drop (owner decision 2026-10-24), cash only. Drop = the recipient pays the rider. --}}
                @if ($paymentMethod === 'cash')
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold text-slate-800">Pay at</span>
                        <div class="inline-flex rounded-full border border-slate-200 p-1" role="radiogroup" aria-label="Pay at">
                            @foreach (['pickup' => 'Pickup', 'drop' => 'Drop'] as $value => $label)
                                <label @class([
                                    'inline-flex min-h-10 cursor-pointer items-center rounded-full px-5 text-sm font-semibold transition',
                                    'bg-blue-600 text-white' => $cashCollectAt === $value,
                                    'text-slate-700 hover:bg-slate-100' => $cashCollectAt !== $value,
                                ])>
                                    <input type="radio" wire:model.live="cashCollectAt" value="{{ $value }}" class="sr-only">{{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif

        </section>

        {{-- Always in reach: price + Book, pinned above the phone's bottom menu (4rem) and at the bottom on desktop. --}}
        <div class="sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] z-30 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border lg:bottom-4">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    @if ($quote)
                        <p class="text-lg font-bold tabular-nums leading-tight">{{ $fmt($quote['total']) }}</p>
                        <p class="truncate text-xs text-slate-500">{{ rtrim(rtrim(number_format($quote['distance_km'], 1), '0'), '.') }} km · {{ $paymentMethod === 'cash' ? ($cashCollectAt === 'drop' ? 'Cash at drop' : 'Cash at pickup') : ($methods[$paymentMethod] ?? '') }}</p>
                    @else
                        <p class="text-sm text-slate-500">{{ $quoteError ?: 'Choose where it goes' }}</p>
                    @endif
                </div>
                <button type="submit" @disabled(! $quote) wire:loading.attr="disabled" wire:target="submit"
                        class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl bg-blue-600 px-6 text-base font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                    <span wire:loading.remove wire:target="submit">{{ $paymentMethod === 'online' ? 'Pay & book' : 'Book now' }}</span>
                    <span wire:loading wire:target="submit">Booking…</span>
                </button>
            </div>
        </div>
    </form>

    @assets
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    @endassets
    @script
    <script>
        // Map search for both points — the shared helper in resources/js/places-autocomplete.js.
        const wirePlaces = (which) => {
            const wrap = document.querySelector('[data-parcel-places="' + which + '"]');
            if (! wrap || ! window.cfPlacesAutocomplete) return;
            window.cfPlacesAutocomplete.mount({
                input: wrap.querySelector('[data-places-input]'),
                list: wrap.querySelector('[data-places-results]'),
                onReady: () => { wrap.hidden = false; },
                onSelect: (place) => {
                    const line = place.formattedAddress || place.label || '';
                    which === 'pickup' ? $wire.setPickupPin(place.lat, place.lng, line) : $wire.setDropPin(place.lat, place.lng, line);
                },
                onError: (message) => {
                    const error = wrap.querySelector('[data-places-error]');
                    if (! error) return;
                    error.textContent = message || '';
                    error.hidden = ! message;
                },
            });
        };
        wirePlaces('pickup');
        wirePlaces('drop');

        // "Use my current location" for pickup — the shared helper in resources/js/geolocation.js. With no saved
        // address to default to, it runs straight away so pickup needs no tap at all.
        const locate = document.querySelector('[data-parcel-locate]');
        if (locate && navigator.geolocation && window.cfLocate) {
            locate.hidden = false;
            const findMe = () => {
                const label = locate.querySelector('[data-parcel-locate-label]');
                label.textContent = 'Finding your location…';
                window.cfLocate(
                    (lat, lng) => { label.textContent = 'Use my current location'; $wire.setPickupPin(lat, lng, 'My current location'); },
                    () => { label.textContent = 'Could not get your location. Try the search.'; },
                );
            };
            locate.addEventListener('click', findMe);
            if ($wire.pickupMode === 'pin' && $wire.pickupLat === null) findMe();
        }

        // Contact Picker API (Android Chrome): one tap for the recipient's name and number.
        const contacts = document.querySelector('[data-parcel-contacts]');
        if (contacts && 'contacts' in navigator && 'ContactsManager' in window) {
            contacts.hidden = false;
            contacts.addEventListener('click', async () => {
                try {
                    const [picked] = await navigator.contacts.select(['name', 'tel'], { multiple: false });
                    if (picked && picked.tel && picked.tel[0]) $wire.setRecipient((picked.name && picked.name[0]) || '', picked.tel[0]);
                } catch (e) { /* the customer closed the picker */ }
            });
        }

        $wire.on('razorpay-open', (e) => {
            const o = e.order ?? e[0]?.order;
            const redirect = e.redirect ?? e[0]?.redirect;
            if (! o || ! window.Razorpay) { if (redirect) window.location = redirect; return; }
            new window.Razorpay({
                key: o.razorpay_key_id,
                order_id: o.razorpay_order_id,
                amount: o.amount,
                currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                description: 'Parcel delivery',
                handler: () => { window.location = redirect; },
                modal: { ondismiss: () => { window.location = redirect; } },
            }).open();
        });
    </script>
    @endscript
</div>
