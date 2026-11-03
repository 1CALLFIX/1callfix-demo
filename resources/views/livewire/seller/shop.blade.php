<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $store->name }}</h1>
        <p class="text-sm text-slate-500">To change the shop name, contact support.</p>
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-4">
        <x-ui.card>
            <h2 class="text-sm font-semibold">About your shop</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Description</span>
                    <textarea rows="3" wire:model="description" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-base" placeholder="What you sell, since when, what makes you different"></textarea>
                    @error('description') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Shop phone</span>
                    <input type="tel" wire:model="phone" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    @error('phone') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
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
                                <span class="text-sm text-slate-500">Closed</span>
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
            <p class="mt-1 text-xs text-slate-500">Printed on the tax invoice your customers get. Leave blank if you are not GST-registered.</p>
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
</div>

@script
<script>
    window.cfWireLocateButton($wire);
</script>
@endscript
