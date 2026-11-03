<div class="space-y-4" x-data x-on:seller-scroll-top.window="$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $store->name }}</h1>
        <p class="text-sm text-slate-500">To change the shop name, contact support.</p>
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
        <h2 class="text-sm font-semibold">Shop mobile number</h2>
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
            <h2 class="text-sm font-semibold">About your shop</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Description</span>
                    <textarea rows="3" wire:model="description" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-base" placeholder="What you sell, since when, what makes you different"></textarea>
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
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold">Where riders collect orders</h2>
            <p class="mt-1 text-xs text-slate-500">A new address or pin is checked by 1CallFix first; riders keep using the old one until then.</p>
            <label class="mt-3 block text-sm">
                <span class="font-medium">Shop address</span>
                <textarea rows="2" wire:model="addressLine" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-base"></textarea>
                @error('addressLine') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
            </label>
            <button type="button" data-locate-address
                    class="mt-2 inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50">
                <span data-locate-address-label>Set the pin to where I am now</span>
            </button>
            <p data-locate-address-error role="alert" hidden class="mt-2 text-sm text-amber-800">We could not get your location. Stand inside your shop and try again.</p>
            <p class="mt-2 text-xs text-slate-500">Pin: {{ $lat !== null ? number_format($lat, 5).', '.number_format($lng, 5) : 'not set' }}. Stand inside your shop when you set it.</p>
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

        <x-ui.button type="submit" wire:loading.attr="disabled">Save shop details</x-ui.button>
    </form>

    {{-- Firebase phone OTP (same as sign-up), only for changing the shop mobile number. --}}
    @vite('resources/js/customer-auth.js')
</div>

@script
<script>
    window.cfWireLocateButton($wire);
</script>
@endscript
