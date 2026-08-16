<div>
    <h1 class="text-2xl font-bold mb-1">Geography</h1>
    <p class="text-sm text-gray-500 mb-4">Countries and cities — the reference data Franchises, Bookings, and Settings scope resolve against. Zones live under a Franchise, not here.</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">New Country</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" class="w-48 border rounded px-3 py-2 text-sm">
                @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">ISO code (2-letter) <span class="text-red-500">*</span></label>
                <input type="text" wire:model="code" maxlength="2" placeholder="IN" class="w-24 border rounded px-3 py-2 text-sm uppercase">
                @error('code') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Currency (3-letter) <span class="text-red-500">*</span></label>
                <input type="text" wire:model="currencyCode" maxlength="3" placeholder="INR" class="w-24 border rounded px-3 py-2 text-sm uppercase">
                @error('currencyCode') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Default timezone <span class="text-red-500">*</span></label>
                <input type="text" wire:model="defaultTimezone" placeholder="Asia/Kolkata" class="w-40 border rounded px-3 py-2 text-sm">
                @error('defaultTimezone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <x-ui.button class="h-[38px]" wire:click="saveCountry">Add Country</x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.table>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Country</th>
                <th class="px-4 py-2">Code</th>
                <th class="px-4 py-2">Currency</th>
                <th class="px-4 py-2">Timezone</th>
                <th class="px-4 py-2">Cities</th>
                <th class="px-4 py-2">Franchises</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($countries as $country)
                <tr class="border-t hover:bg-gray-50" wire:key="country-{{ $country->id }}">
                    <td class="px-4 py-2 font-medium">{{ $country->name }}</td>
                    <td class="px-4 py-2 font-mono">{{ $country->code }}</td>
                    <td class="px-4 py-2">{{ $country->currency_code }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $country->default_timezone }}</td>
                    <td class="px-4 py-2">{{ $country->cities_count }}</td>
                    <td class="px-4 py-2">{{ $country->franchises_count }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="$country->is_active ? 'green' : 'gray'">
                            {{ $country->is_active ? 'active' : 'inactive' }}
                        </x-ui.badge>
                    </td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <x-ui.button variant="ghost" class="mr-3" wire:click="expand({{ $country->id }})">{{ $expandedCountryId === $country->id ? 'Hide cities' : 'Cities' }}</x-ui.button>
                        <x-ui.button variant="ghost" color="gray" class="mr-3" wire:click="toggleCountryActive({{ $country->id }})">{{ $country->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                        <x-ui.button variant="ghost" color="red" wire:click="deleteCountry({{ $country->id }})" wire:confirm="Delete this country?">Delete</x-ui.button>
                    </td>
                </tr>
                    @if ($expandedCountryId === $country->id)
                        <tr class="border-t bg-gray-50" wire:key="country-{{ $country->id }}-cities">
                            <td colspan="8" class="px-4 py-4">
                                <table class="w-full text-xs mb-3">
                                    <thead class="text-left text-gray-500"><tr><th class="pr-3 py-1">City</th><th class="pr-3 py-1">Franchises</th><th class="pr-3 py-1">Status</th><th class="pr-3 py-1"></th></tr></thead>
                                    <tbody>
                                        @forelse (($citiesByCountry[$country->id] ?? collect()) as $city)
                                            <tr class="border-t" wire:key="city-{{ $city->id }}">
                                                <td class="pr-3 py-1">{{ $city->name }}</td>
                                                <td class="pr-3 py-1">{{ $city->franchises_count }}</td>
                                                <td class="pr-3 py-1">{{ $city->is_active ? 'active' : 'inactive' }}</td>
                                                <td class="pr-3 py-1 text-right whitespace-nowrap">
                                                    <x-ui.button variant="ghost" class="mr-2" wire:click="toggleCityActive({{ $city->id }})">{{ $city->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                                                    <x-ui.button variant="ghost" color="red" wire:click="deleteCity({{ $city->id }})" wire:confirm="Delete this city?">Delete</x-ui.button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="py-2 text-gray-400">No cities in {{ $country->name }} yet.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                                <div class="flex items-end gap-2">
                                    <div>
                                        <label class="block text-xs mb-1">New city name</label>
                                        <input type="text" wire:model="cityName" class="border rounded px-2 py-1.5 text-xs w-48">
                                        @error('cityName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <x-ui.button size="sm" wire:click="saveCity">Add City</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No countries yet.</td></tr>
                @endforelse
            </tbody>
    </x-ui.table>
</div>
