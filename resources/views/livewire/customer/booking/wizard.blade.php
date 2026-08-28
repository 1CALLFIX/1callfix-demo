{{--
    Phase E6 booking wizard. Four steps on one Livewire component; the server
    re-validates each gate in Wizard.php before it will advance. Every price
    shown here is CatalogPresenter's estimate — the authoritative charge is
    computed by CreateBookingAction at booking time, and the screen says so.
--}}
@php
    $stepLabels = ['configure' => 'Options', 'address' => 'Address', 'schedule' => 'Time', 'pay' => 'Payment'];
    $currentIndex = array_search($step, $steps, true);
    $estimate = $baseEstimate + $optionsEstimate;
@endphp

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">

    <a href="{{ route('customer.services.show', $service) }}"
       class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
        <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $service->name }}
    </a>

    <h1 class="mt-3 text-2xl font-bold tracking-tight">Book {{ $service->name }}</h1>

    {{-- Step rail. Colour is never the only signal (WCAG 1.4.1): the current
         step also carries a ring and bold weight, completed steps a check. --}}
    <ol class="mt-5 flex items-center gap-2" aria-label="Booking steps">
        @foreach ($steps as $i => $s)
            <li class="flex flex-1 items-center gap-2">
                <span @class([
                    'grid h-8 w-8 shrink-0 place-items-center rounded-full text-xs font-bold',
                    'bg-slate-900 text-white ring-2 ring-slate-900 ring-offset-2' => $s === $step,
                    'bg-emerald-100 text-emerald-700' => $i < $currentIndex,
                    'bg-slate-100 text-slate-500' => $i > $currentIndex,
                ])>
                    @if ($i < $currentIndex)
                        <x-icon name="check" class="h-4 w-4" />
                    @else
                        {{ $i + 1 }}
                    @endif
                </span>
                <span @class(['text-sm', 'font-semibold text-slate-900' => $s === $step, 'text-slate-500' => $s !== $step])>
                    {{ $stepLabels[$s] }}
                </span>
                @if (! $loop->last)
                    <span aria-hidden="true" class="h-px flex-1 bg-slate-200"></span>
                @endif
            </li>
        @endforeach
    </ol>

    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $error }}
        </div>
    @endif

    <div class="mt-5 grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="min-w-0">

            {{-- ============================ STEP: configure ============================ --}}
            @if ($step === 'configure')
                <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                    <h2 class="text-base font-semibold">Configure your service</h2>

                    @forelse ($groups as $group)
                        <fieldset class="mt-4">
                            <legend class="text-sm font-medium text-slate-800">
                                {{ $group->name }}
                                @if ($group->is_required)
                                    <span class="text-rose-600">*</span>
                                @endif
                            </legend>
                            <div class="mt-2 space-y-2">
                                @foreach ($group->options as $option)
                                    @php
                                        $isMulti = $group->allow_multiple;
                                        $selectedForGroup = (array) ($selected[$group->id] ?? []);
                                        $checked = in_array($option->id, array_map('intval', $selectedForGroup), true);
                                    @endphp
                                    <label class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2.5 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                                        <span class="flex items-center gap-2.5">
                                            <input
                                                type="{{ $isMulti ? 'checkbox' : 'radio' }}"
                                                @if ($checked) checked @endif
                                                wire:click="{{ $isMulti ? 'toggleOption' : 'selectOption' }}({{ $group->id }}, {{ $option->id }})"
                                                class="h-4 w-4 accent-slate-900"
                                            >
                                            <span>{{ $option->name }}</span>
                                        </span>
                                        @if ((float) $option->price_delta !== 0.0)
                                            <span class="text-slate-500">
                                                {{ (float) $option->price_delta > 0 ? '+' : '−' }}{{ $currencySymbol }}{{ number_format(abs((float) $option->price_delta), 2) }}
                                            </span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @empty
                        <p class="mt-3 text-sm text-slate-600">This service has no options to configure — continue to choose where and when.</p>
                    @endforelse

                    <label class="mt-5 block text-sm font-medium text-slate-800" for="customerNote">Anything the professional should know? (optional)</label>
                    <textarea id="customerNote" wire:model="customerNote" rows="3" maxlength="1000"
                              class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                              placeholder="Gate code, which floor, what's broken…"></textarea>
                </section>
            @endif

            {{-- ============================ STEP: address ============================ --}}
            @if ($step === 'address')
                <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">Where is the job?</h2>
                        <a href="{{ route('customer.addresses') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-900">Manage addresses</a>
                    </div>

                    @if ($addresses->isEmpty() && ! $addingAddress)
                        <p class="mt-3 text-sm text-slate-600">You have no saved addresses yet.</p>
                    @endif

                    <div class="mt-3 space-y-2">
                        @foreach ($addresses as $address)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 px-3 py-3 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                                <input type="radio" name="addressId" wire:model.live="addressId" value="{{ $address->id }}"
                                       @disabled(! $address->zone_id)
                                       class="mt-0.5 h-4 w-4 accent-slate-900">
                                <span class="min-w-0">
                                    <span class="font-medium text-slate-900">{{ $address->label }}</span>
                                    @if ($address->is_default)
                                        <span class="ml-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">Default</span>
                                    @endif
                                    <span class="block text-slate-600">{{ $address->address_line }}@if ($address->city), {{ $address->city }}@endif</span>
                                    @unless ($address->zone_id)
                                        <span class="block text-rose-600">This address has no service area set and can't be used for a booking.</span>
                                    @endunless
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @if ($addingAddress)
                        <div class="mt-4 rounded-lg bg-slate-50 p-3">
                            <p class="text-sm font-medium">Add an address</p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <input wire:model="newAddress.label" placeholder="Label (Home, Office)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <input wire:model="newAddress.city" placeholder="City" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <input wire:model="newAddress.address_line" placeholder="Flat / street / area" class="rounded-lg border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                                <input wire:model="newAddress.landmark" placeholder="Landmark (optional)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <input wire:model="newAddress.pincode" placeholder="PIN code (optional)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            </div>
                            @error('newAddress.address_line') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            <div class="mt-3 flex gap-2">
                                <button wire:click="saveNewAddress" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save address</button>
                                <button wire:click="$set('addingAddress', false)" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">Cancel</button>
                            </div>
                        </div>
                    @else
                        <button wire:click="$set('addingAddress', true)"
                                class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            <x-icon name="plus" class="h-4 w-4" /> Add a new address
                        </button>
                    @endif
                </section>
            @endif

            {{-- ============================ STEP: schedule ============================ --}}
            @if ($step === 'schedule')
                <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                    <h2 class="text-base font-semibold">When should we come?</h2>
                    <div class="mt-3 space-y-2">
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-3 py-3 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50">
                            <input type="radio" wire:model.live="scheduledAt" value="" class="h-4 w-4 accent-slate-900">
                            <span><span class="font-medium">As soon as possible</span><span class="block text-slate-600">We match you with the next available professional.</span></span>
                        </label>
                        <div class="rounded-lg border border-slate-200 px-3 py-3">
                            <p class="text-sm font-medium">Pick a date &amp; time</p>
                            <input type="datetime-local" wire:model.live="scheduledAt"
                                   class="mt-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Provider availability is confirmed by our system when you book, not in the browser.</p>
                </section>
            @endif

            {{-- ============================ STEP: pay ============================ --}}
            @if ($step === 'pay')
                <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                    <h2 class="text-base font-semibold">How would you like to pay?</h2>

                    <div class="mt-3 space-y-2">
                        @foreach ($enabledMethods as $key => $label)
                            @php
                                $walletShort = $key === 'wallet' && $walletBalance < $estimate;
                            @endphp
                            <label @class([
                                'flex cursor-pointer items-center justify-between gap-3 rounded-lg border px-3 py-3 text-sm',
                                'border-slate-200 hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-50' => ! $walletShort,
                                'border-slate-200 opacity-60' => $walletShort,
                            ])>
                                <span class="flex items-center gap-2.5">
                                    <input type="radio" wire:model.live="paymentMethod" value="{{ $key }}" @disabled($walletShort) class="h-4 w-4 accent-slate-900">
                                    <span>
                                        <span class="font-medium capitalize">{{ $label }}</span>
                                        @if ($key === 'wallet')
                                            <span class="block text-slate-600">Balance {{ $currencySymbol }}{{ number_format($walletBalance, 2) }}
                                                @if ($walletShort) — not enough for this booking @endif
                                            </span>
                                        @elseif ($key === 'online')
                                            <span class="block text-slate-600">Card / UPI / netbanking via Razorpay, on the next screen.</span>
                                        @elseif ($key === 'cash')
                                            <span class="block text-slate-600">Pay the professional after the job.</span>
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                        @if (empty($enabledMethods))
                            <p class="text-sm text-rose-600">No payment methods are enabled for this area yet.</p>
                        @endif
                    </div>

                    @if ($walletBalance < $estimate)
                        <a href="{{ route('customer.wallet') }}" wire:navigate class="mt-3 inline-block text-sm font-medium text-slate-700 underline">Top up your wallet</a>
                    @endif

                    <p class="mt-4 text-xs text-slate-500">
                        The amount above is an estimate. Your final price is computed by our server from the live price for your area
                        when you confirm, and shown on your order.
                    </p>
                </section>
            @endif

            {{-- Nav buttons --}}
            <div class="mt-4 flex items-center justify-between">
                @if ($currentIndex > 0)
                    <button wire:click="back" class="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Back</button>
                @else
                    <span></span>
                @endif

                @if ($step !== 'pay')
                    <button wire:click="next" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Continue</button>
                @else
                    <button wire:click="placeBooking" wire:loading.attr="disabled" wire:target="placeBooking"
                            class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        <span wire:loading.remove wire:target="placeBooking">Confirm booking</span>
                        <span wire:loading wire:target="placeBooking">Booking…</span>
                    </button>
                @endif
            </div>
        </div>

        {{-- Sticky summary --}}
        <aside class="lg:sticky lg:top-20 lg:self-start">
            <div class="rounded-xl border border-slate-200 p-4">
                <p class="text-sm font-semibold">{{ $service->name }}</p>
                <p class="text-xs text-slate-500">{{ $service->category?->name }}</p>

                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Base ({{ $card['price_prefix'] }})</dt><dd>{{ $currencySymbol }}{{ number_format($baseEstimate, 2) }}</dd></div>
                    @if ($optionsEstimate != 0)
                        <div class="flex justify-between"><dt class="text-slate-600">Options</dt><dd>{{ $optionsEstimate > 0 ? '+' : '−' }}{{ $currencySymbol }}{{ number_format(abs($optionsEstimate), 2) }}</dd></div>
                    @endif
                    <div class="mt-1 flex justify-between border-t border-slate-200 pt-2 font-semibold">
                        <dt>Estimated total</dt><dd>{{ $currencySymbol }}{{ number_format($estimate, 2) }}</dd>
                    </div>
                </dl>

                <p class="mt-2 text-[11px] leading-snug text-slate-500">Estimate only — the server confirms your final price when you book.</p>

                @if ($step !== 'configure')
                    <div class="mt-3 border-t border-slate-200 pt-3 text-xs text-slate-600">
                        @php $addr = $addresses->firstWhere('id', $addressId); @endphp
                        @if ($addr)<p><span class="text-slate-400">Address:</span> {{ $addr->label }}</p>@endif
                        <p><span class="text-slate-400">Time:</span> {{ $scheduledAt ? \Illuminate\Support\Carbon::parse($scheduledAt)->format('D j M, g:i A') : 'As soon as possible' }}</p>
                        @if ($step === 'pay')<p class="capitalize"><span class="text-slate-400">Pay:</span> {{ $paymentMethod }}</p>@endif
                    </div>
                @endif
            </div>
        </aside>
    </div>
</div>
