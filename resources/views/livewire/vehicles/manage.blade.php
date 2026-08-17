<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-lg font-bold mb-3">New Vehicle</h2>

        <div class="grid grid-cols-3 gap-4 mb-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Owner (Provider)</label>
                <select wire:model="providerId" class="border rounded px-3 py-2 text-sm w-full">
                    <option value="">Select owner...</option>
                    @foreach ($providers as $p)
                        <option value="{{ $p->id }}">{{ $p->user?->name }}</option>
                    @endforeach
                </select>
                @error('providerId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                <select wire:model="vehicleCategoryId" class="border rounded px-3 py-2 text-sm w-full">
                    <option value="">Select category...</option>
                    @foreach ($vehicleCategories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Zone</label>
                <select wire:model="zoneId" class="border rounded px-3 py-2 text-sm w-full">
                    <option value="">Select zone...</option>
                    @foreach ($zones as $z)
                        <option value="{{ $z->id }}">{{ $z->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-3">
            <input type="text" wire:model="make" placeholder="Make" class="border rounded px-3 py-2 text-sm">
            <input type="text" wire:model="model" placeholder="Model" class="border rounded px-3 py-2 text-sm">
        </div>

        <div class="mb-3">
            <label class="block text-xs font-medium text-gray-600 mb-1">Rental modes offered</label>
            <label class="inline-flex items-center gap-2 text-sm mr-4">
                <input type="checkbox" value="self_drive" wire:model="supportedRentalModes"> Self-Drive
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" value="with_driver" wire:model="supportedRentalModes"> With Driver
            </label>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-3">
            <input type="number" step="any" wire:model="basePrice" placeholder="Base price" class="border rounded px-3 py-2 text-sm">
            <select wire:model="pricingUnit" class="border rounded px-3 py-2 text-sm">
                @foreach (['hourly','daily','weekly','monthly'] as $u)
                    <option value="{{ $u }}">{{ ucfirst($u) }}</option>
                @endforeach
            </select>
            <input type="number" step="any" wire:model="depositAmount" placeholder="Deposit (optional)" class="border rounded px-3 py-2 text-sm">
        </div>

        <x-ui.button wire:click="createVehicle">Create Vehicle</x-ui.button>
    </x-ui.card>

    <h1 class="text-2xl font-bold mb-4">Vehicles</h1>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by make or model..." class="border rounded px-3 py-2 text-sm w-96">
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $vehicles->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Vehicle</th>
                <th class="px-4 py-2">Category</th>
                <th class="px-4 py-2">Owner</th>
                <th class="px-4 py-2">Modes</th>
                <th class="px-4 py-2">Base price</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($vehicles as $v)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $v->make }} {{ $v->model }}</td>
                    <td class="px-4 py-2">{{ $v->vehicleCategory?->name }}</td>
                    <td class="px-4 py-2">{{ $v->provider?->user?->name }}</td>
                    <td class="px-4 py-2 text-xs">{{ implode(', ', $v->supported_rental_modes ?? []) }}</td>
                    <td class="px-4 py-2">{{ number_format($v->base_price, 2) }} / {{ $v->pricing_unit }}</td>
                    <td class="px-4 py-2">
                        @if ($v->is_active) <x-ui.badge color="green">Active</x-ui.badge> @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                    </td>
                    <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="editVehicle({{ $v->id }})">Edit</x-ui.button></td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>

    <x-ui.modal :show="$showEditModal" title="Edit Vehicle">
        <input type="text" wire:model="editMake" placeholder="Make" class="border rounded px-3 py-2 text-sm w-full mb-3">
        <input type="text" wire:model="editModel" placeholder="Model" class="border rounded px-3 py-2 text-sm w-full mb-3">
        <label class="flex items-center gap-2 text-sm mb-3">
            <input type="checkbox" wire:model="editIsActive"> Active
        </label>
        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeEditModal">Cancel</x-ui.button>
            <x-ui.button wire:click="saveEdit">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
