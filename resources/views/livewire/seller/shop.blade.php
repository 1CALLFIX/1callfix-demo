@php
    // Restaurant portal 2026-11-04: a restaurant's own page says "restaurant", never "shop".
    $place = $store->isRestaurant() ? 'restaurant' : 'shop';
    $noHoursAtAll = $store->isRestaurant() && collect($hours)->flatten(1)->isEmpty();
@endphp
<div class="space-y-4" x-data x-on:seller-scroll-top.window="$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $store->name }}</h1>
        <p class="text-sm text-slate-500">To change the {{ $place }} name, contact support.</p>
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    {{-- Owner rule 2026-11-03: address, map pin, GSTIN and PAN wait for approval; the old ones stay until then. --}}
    @if ($latestChange?->status === \App\Models\PendingChange::PENDING)
        <p class="rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900" data-testid="shop-change-waiting">Your new {{ mb_strtolower($latestChange->summary()) }} {{ str_contains($latestChange->summary(), ',') ? 'are' : 'is' }} waiting for approval (shown below). Riders and invoices use the old details until then.</p>
    @elseif ($latestChange?->status === \App\Models\PendingChange::REJECTED)
        <p class="rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-800" data-testid="shop-change-rejected">Your change to the {{ mb_strtolower($latestChange->summary()) }} was not approved: {{ $latestChange->review_note }}</p>
    @endif

    {{-- Shop mobile number: changes only after an OTP to the new number (owner rule 2026-11-03). --}}
    <x-ui.card>
        <h2 class="text-sm font-semibold">{{ ucfirst($place) }} mobile number</h2>
        <p class="mt-1 text-sm text-slate-700">{{ $store->phone ?: 'Not set' }}</p>
        @if ($phoneStep === '')
            <button type="button" wire:click="$set('phoneStep', 'number')" class="mt-2 inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 hover:bg-slate-50">Change number</button>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                <label class="block text-sm">
                    <span class="font-medium">New mobile number</span>
                    <input type="tel" wire:model="newPhone" inputmode="tel" maxlength="16" @disabled($phoneStep === 'code') class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base disabled:bg-slate-50">
                    @error('newPhone') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                @if ($phoneStep === 'number')
                    <x-ui.button type="button" wire:click="sendPhoneCode">Send code</x-ui.button>
                @endif
            </div>
            @if ($phoneStep === 'code')
                <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" x-data="{ code: '' }">
                    <label class="block text-sm">
                        <span class="font-medium">Code sent by SMS to the new number</span>
                        <input type="text" x-model="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base tracking-widest">
                    </label>
                    <x-ui.button type="button" x-on:click="window.customerAuth && window.customerAuth.confirmPhone(code)">Verify and change</x-ui.button>
                </div>
            @endif
            @if ($phoneError)<p class="mt-2 text-sm text-red-700" role="alert">{{ $phoneError }}</p>@endif
            <button type="button" wire:click="cancelPhoneChange" class="mt-2 text-sm text-slate-600 underline">Cancel</button>
        @endif
        <div id="firebase-recaptcha" wire:ignore></div>
    </x-ui.card>

    <form wire:submit="save" class="space-y-4">
        <x-ui.card>
            <h2 class="text-sm font-semibold">About your {{ $place }}</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Description</span>
                    <textarea rows="3" wire:model="description" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-base" placeholder="{{ $store->isRestaurant() ? 'Your speciality, since when, what makes your food different' : 'What you sell, since when, what makes you different' }}"></textarea>
                    @error('description') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <div class="text-sm">
                    <span class="font-medium">Logo</span>
                    <div class="mt-1 flex items-center gap-3">
                        @if ($logo && method_exists($logo, 'isPreviewable') && $logo->isPreviewable())
                            <img src="{{ $logo->temporaryUrl() }}" alt="" class="h-12 w-12 rounded-lg object-cover">
                        @elseif ($store->logo_url)
                            <img src="{{ $store->logo_url }}" alt="" class="h-12 w-12 rounded-lg object-cover">
                        @endif
                        <input type="file" wire:model="logo" accept="image/jpeg,image/png,image/webp" class="text-sm">
                    </div>
                    @error('logo') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </div>
                <div class="text-sm sm:col-span-2">
                    <span class="font-medium">Cover photo</span>
                    <span class="block text-xs text-slate-500">A wide photo of your {{ $store->isRestaurant() ? 'best dish or your restaurant' : 'shop or products' }}. Customers see it first.</span>
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        @if ($cover && method_exists($cover, 'isPreviewable') && $cover->isPreviewable())
                            <img src="{{ $cover->temporaryUrl() }}" alt="" class="h-20 w-40 rounded-lg object-cover">
                        @elseif ($store->cover_url)
                            <img src="{{ $store->cover_url }}" alt="" class="h-20 w-40 rounded-lg object-cover">
                        @endif
                        <input type="file" wire:model="cover" accept="image/jpeg,image/png,image/webp" class="text-sm" data-testid="cover-input">
                    </div>
                    @error('cover') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold">Where riders collect orders</h2>
            <p class="mt-1 text-xs text-slate-500">A new address or pin is checked by 1CallFix first; riders keep using the old one until then.</p>
            <label class="mt-3 block text-sm">
                <span class="font-medium">{{ ucfirst($place) }} address</span>
                <textarea rows="2" wire:model="addressLine" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-base"></textarea>
                @error('addressLine') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
            </label>
            <button type="button" data-locate-address
                    class="mt-2 inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50">
                <span data-locate-address-label>Set the pin to where I am now</span>
            </button>
            <p data-locate-address-error role="alert" hidden class="mt-2 text-sm text-amber-800">We could not get your location. Stand inside your {{ $place }} and try again.</p>
            <p class="mt-2 text-xs text-slate-500">Pin: {{ $lat !== null ? number_format($lat, 5).', '.number_format($lng, 5) : 'not set' }}. Stand inside your {{ $place }} when you set it.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold">Order terms</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                <label class="block text-sm">
                    <span class="font-medium">Minimum order ({{ $currencySymbol }})</span>
                    <input type="text" inputmode="decimal" wire:model="minimumOrder" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    @error('minimumOrder') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Delivery fee ({{ $currencySymbol }})</span>
                    <input type="text" inputmode="decimal" wire:model="deliveryFee" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    @error('deliveryFee') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Free delivery above ({{ $currencySymbol }})</span>
                    <input type="text" inputmode="decimal" wire:model="freeDeliveryAbove" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="Blank = never free">
                    @error('freeDeliveryAbove') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
            </div>
            <p class="mt-2 text-xs text-slate-500">The delivery fee goes to the rider. Free delivery applies to online payments only; cash orders always pay the fee.</p>
        </x-ui.card>

        @if ($store->isRestaurant())
            {{-- Food F1: restaurant details. The lists and limits come from Admin → Food Settings. --}}
            <x-ui.card>
                <h2 class="text-sm font-semibold">Restaurant</h2>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="font-medium">FSSAI licence number</span>
                        <input type="text" inputmode="numeric" maxlength="14" wire:model="fssai" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="14 digits">
                        <span class="text-xs text-slate-500">Shown on your menu. Needed before your restaurant opens.</span>
                        @error('fssai') <span class="block text-sm text-red-700">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">Cost for two ({{ $currencySymbol }})</span>
                        <input type="text" inputmode="decimal" wire:model="costForTwo" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="For example 300">
                        @error('costForTwo') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">Usual cooking time (minutes)</span>
                        <input type="text" inputmode="numeric" wire:model="prepMinutes" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="Blank = {{ $defaultPrep }}">
                        @error('prepMinutes') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                    </label>
                    @if ($packagingMax !== null)
                        <label class="block text-sm">
                            <span class="font-medium">Packaging charge per order ({{ $currencySymbol }})</span>
                            <input type="text" inputmode="decimal" wire:model="packagingCharge" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="Up to {{ $currencySymbol }}{{ rtrim(rtrim(number_format($packagingMax, 2), '0'), '.') }}">
                            @error('packagingCharge') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                        </label>
                    @endif
                </div>
                <fieldset class="mt-4">
                    <legend class="text-sm font-medium">Cuisines (up to 3)</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($cuisineOptions as $cuisine)
                            <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-full border border-slate-300 bg-white px-3 text-sm has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50">
                                <input type="checkbox" value="{{ $cuisine }}" wire:model="cuisines" class="rounded"> {{ $cuisine }}
                            </label>
                        @endforeach
                    </div>
                    @error('cuisines') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                    @error('cuisines.*') <span class="text-sm text-red-700">Choose from the list.</span> @enderror
                </fieldset>
            </x-ui.card>

            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Opening hours</h2>
                    <button type="button" wire:click="copyMondayToAll" class="text-sm font-medium text-blue-700 underline">Same as Monday every day</button>
                </div>
                <p class="mt-1 text-xs text-slate-500">Customers can order only inside these hours while you are open. No hours at all = whenever you are open. A closing time earlier than the opening time runs past midnight.</p>
                @if ($noHoursAtAll)
                    <p class="mt-2 rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-900" data-testid="no-hours-note">No set hours: customers can order whenever you switch your restaurant Open on Home. Add times below to open only at those times.</p>
                @endif
                <div class="mt-3 divide-y divide-slate-100">
                    @foreach ($dayNames as $day => $dayName)
                        <div class="flex flex-wrap items-center gap-2 py-2" wire:key="day-{{ $day }}">
                            <span class="w-24 text-sm font-medium">{{ $dayName }}</span>
                            @forelse ($hours[$day] ?? [] as $i => $slot)
                                <span class="inline-flex items-center gap-1" wire:key="slot-{{ $day }}-{{ $i }}">
                                    <input type="time" wire:model="hours.{{ $day }}.{{ $i }}.opens" aria-label="{{ $dayName }} opens" class="min-h-10 rounded-lg border border-slate-300 px-2 text-sm">
                                    <span class="text-slate-400">–</span>
                                    <input type="time" wire:model="hours.{{ $day }}.{{ $i }}.closes" aria-label="{{ $dayName }} closes" class="min-h-10 rounded-lg border border-slate-300 px-2 text-sm">
                                    <button type="button" wire:click="removeSlot({{ $day }}, {{ $i }})" class="px-1 text-slate-500 hover:text-red-700" aria-label="Remove">&times;</button>
                                </span>
                                @error("hours.$day.$i.opens") <span class="w-full text-sm text-red-700">{{ $message }}</span> @enderror
                                @error("hours.$day.$i.closes") <span class="w-full text-sm text-red-700">{{ $message }}</span> @enderror
                            @empty
                                <span class="text-sm text-slate-500">{{ $noHoursAtAll ? 'No set hours' : 'Closed' }}</span>
                            @endforelse
                            @if (count($hours[$day] ?? []) < 3)
                                <button type="button" wire:click="addSlot({{ $day }})" class="text-sm font-medium text-blue-700">+ Add time</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        <x-ui.card>
            <h2 class="text-sm font-semibold">Tax details</h2>
            <p class="mt-1 text-xs text-slate-500">Printed on the tax invoice your customers get. Leave blank if you are not GST-registered. A change is checked by 1CallFix first.</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="block text-sm"><span class="font-medium">GSTIN</span>
                    <input type="text" wire:model="gstin" maxlength="15" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base uppercase">
                    @error('gstin') <span class="text-sm text-red-700">{{ $message }}</span> @enderror</label>
                <label class="block text-sm"><span class="font-medium">PAN</span>
                    <input type="text" wire:model="pan" maxlength="10" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base uppercase">
                    @error('pan') <span class="text-sm text-red-700">{{ $message }}</span> @enderror</label>
            </div>
        </x-ui.card>

        <x-ui.button type="submit" wire:loading.attr="disabled">Save {{ $place }} details</x-ui.button>
    </form>

    {{-- Firebase phone OTP (same as sign-up), only for changing the shop mobile number. --}}
    @vite('resources/js/customer-auth.js')
</div>

@script
<script>
    window.cfWireLocateButton($wire);
</script>
@endscript
