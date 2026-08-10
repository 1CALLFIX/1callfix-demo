<div>
    <h1 class="text-2xl font-bold mb-4">Settings</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    <div class="bg-white rounded-lg shadow-sm p-4 max-w-3xl space-y-6">
        {{-- Dispatch — feeds ServiceMatchingJob's tuning values directly. --}}
        <div>
            <label class="block text-sm font-medium">Dispatch</label>
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
        </div>

        {{-- Commission Defaults — pre-fills Franchises' Add New form. --}}
        <div class="border-t pt-4">
            <label class="block text-sm font-medium">Commission Defaults</label>
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
        </div>

        {{-- Platform / Branding — admin header, <title>, login page. --}}
        <div class="border-t pt-4">
            <label class="block text-sm font-medium">Platform / Branding</label>
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
        </div>

        {{-- Not built yet — shown so the full eventual shape is visible,
             same convention as the sidebar's greyed-out unbuilt items. No
             inputs: nothing in the app reads these yet, so there's nothing
             real for a toggle here to do. --}}
        @foreach ([
            'Payment' => 'Gateway mode, retry rules — payments currently run through Razorpay config directly.',
            'Notification' => 'Channel toggles — no notification service exists yet.',
            'Tax' => 'Tax rate/rules — no tax logic exists yet.',
            'Security' => 'Session/lockout policy — not built yet.',
        ] as $group => $note)
            <div class="border-t pt-4 opacity-50">
                <label class="block text-sm font-medium">{{ $group }} <span class="text-xs font-normal text-gray-400">(coming soon)</span></label>
                <p class="text-xs text-gray-400">{{ $note }}</p>
            </div>
        @endforeach

        <div class="flex justify-end border-t pt-4">
            <button type="button" wire:click="save" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">
                Save Settings
            </button>
        </div>
    </div>
</div>
