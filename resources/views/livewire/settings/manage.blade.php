@php
    $realTabs = [
        'dispatch' => 'Dispatch',
        'commission' => 'Commission Defaults',
        'booking' => 'Booking',
        'locale' => 'Locale & Currency',
        'branding' => 'Platform / Branding',
    ];
    $placeholder = \App\Livewire\Settings\Manage::PLACEHOLDER_TABS[$activeTab] ?? null;
@endphp

<div>
    <h1 class="text-2xl font-bold mb-4">Settings</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    {{-- Tab bar — real tabs first, then placeholders (greyed, "soon" tag),
         so the full eventual shape of a mature settings hub is visible
         without pretending any of them do something they don't. --}}
    <div class="flex items-center gap-1 border-b mb-6 flex-wrap">
        @foreach ($realTabs as $key => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')"
                    @class([
                        'px-4 py-2 text-sm border-b-2 -mb-px whitespace-nowrap',
                        'border-slate-900 text-slate-900 font-medium' => $activeTab === $key,
                        'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $key,
                    ])>{{ $label }}</button>
        @endforeach
        @foreach (\App\Livewire\Settings\Manage::PLACEHOLDER_TABS as $key => $tab)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')"
                    @class([
                        'px-4 py-2 text-sm border-b-2 -mb-px whitespace-nowrap',
                        'border-slate-900 text-slate-500 font-medium' => $activeTab === $key,
                        'border-transparent text-gray-400 hover:text-gray-600' => $activeTab !== $key,
                    ])>{{ $tab['label'] }} <span class="text-[10px]">(soon)</span></button>
        @endforeach
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 max-w-3xl">

        {{-- Dispatch — feeds ServiceMatchingJob's tuning values directly. --}}
        @if ($activeTab === 'dispatch')
            <p class="text-xs text-gray-400 mb-3">Tuning for the automatic provider-matching engine. Changes apply to the next dispatch run — bookings already searching keep their in-flight timing.</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Offer batch size</label>
                    <input type="number" step="1" wire:model="dispatchOfferBatchSize" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Providers offered at once</p>
                    @error('dispatchOfferBatchSize') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Offer timeout (sec)</label>
                    <input type="number" step="1" wire:model="dispatchOfferTimeoutSeconds" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Before an offer expires</p>
                    @error('dispatchOfferTimeoutSeconds') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Max rounds</label>
                    <input type="number" step="1" wire:model="dispatchMaxRounds" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Before falling to manual queue</p>
                    @error('dispatchMaxRounds') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Default radius (km)</label>
                    <input type="number" step="1" wire:model="dispatchDefaultRadiusKm" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Pre-fill for new zones only</p>
                    @error('dispatchDefaultRadiusKm') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveDispatch" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Dispatch Settings</button>
            </div>

        {{-- Commission Defaults — pre-fills Franchises' Add New form. --}}
        @elseif ($activeTab === 'commission')
            <p class="text-xs text-gray-400 mb-3">Pre-fills the Add New Franchise form. Franchises already created keep their own commission settings — edit those individually from the Franchises screen.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Commission model</label>
                    <select wire:model="commissionDefaultModel" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="revenue_share">Revenue Share</option>
                        <option value="flat_fee">Flat Fee</option>
                        <option value="subscription_only">Subscription Only</option>
                    </select>
                    @error('commissionDefaultModel') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Commission value</label>
                    <input type="number" step="0.01" wire:model="commissionDefaultValue" class="w-full border rounded px-3 py-2 text-sm">
                    @error('commissionDefaultValue') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Platform fee (%)</label>
                    <input type="number" step="0.01" wire:model="commissionDefaultPlatformFeePercent" class="w-full border rounded px-3 py-2 text-sm">
                    @error('commissionDefaultPlatformFeePercent') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveCommission" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Commission Defaults</button>
            </div>

        {{-- Booking — OTP generators (AcceptBookingAction, AdminReassignBookingAction)
             and the New Booking modal's scheduling window. --}}
        @elseif ($activeTab === 'booking')
            <p class="text-xs text-gray-400 mb-3">OTP length feeds the start/completion OTPs generated when a provider is assigned. Schedule window bounds how far ahead a booking can be scheduled from the New Booking form.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">OTP length (digits)</label>
                    <input type="number" step="1" wire:model="bookingOtpLength" class="w-full border rounded px-3 py-2 text-sm">
                    @error('bookingOtpLength') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Max schedule days ahead</label>
                    <input type="number" step="1" wire:model="bookingMaxScheduleDaysAhead" class="w-full border rounded px-3 py-2 text-sm">
                    @error('bookingMaxScheduleDaysAhead') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveBooking" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Booking Settings</button>
            </div>

        {{-- Locale & Currency — the ₹ symbol shown across every admin money field. --}}
        @elseif ($activeTab === 'locale')
            <p class="text-xs text-gray-400 mb-3">Currency symbol shown across the admin panel (Bookings, Services, Banners, Dashboard). This changes display only — every money column stays a fixed 2-decimal figure, not a multi-currency conversion.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Currency symbol</label>
                    <input type="text" wire:model="localeCurrencySymbol" class="w-full border rounded px-3 py-2 text-sm" maxlength="5">
                    @error('localeCurrencySymbol') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveLocale" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Locale Settings</button>
            </div>

        {{-- Platform / Branding — admin header, <title>, login page. --}}
        @elseif ($activeTab === 'branding')
            <p class="text-xs text-gray-400 mb-3">Shown in the admin header, browser tab title, and login page.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Platform name</label>
                    <input type="text" wire:model="brandingPlatformName" class="w-full border rounded px-3 py-2 text-sm">
                    @error('brandingPlatformName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Operating city label</label>
                    <input type="text" wire:model="brandingOperatingCityLabel" class="w-full border rounded px-3 py-2 text-sm">
                    @error('brandingOperatingCityLabel') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveBranding" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Branding Settings</button>
            </div>

        {{-- Not built yet — no inputs, since nothing in the app reads a
             setting here. Named honestly, with the reason, rather than
             hidden or faked. --}}
        @elseif ($placeholder)
            <div class="opacity-60 py-6 text-center">
                <p class="text-sm font-medium text-gray-500">{{ $placeholder['label'] }} <span class="text-xs font-normal">(coming soon)</span></p>
                <p class="text-xs text-gray-400 mt-2 max-w-md mx-auto">{{ $placeholder['note'] }}</p>
            </div>
        @endif
    </div>
</div>
