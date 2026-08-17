<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-lg font-bold mb-3">New Store</h2>

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
                <label class="block text-xs font-medium text-gray-600 mb-1">Zone</label>
                <select wire:model="zoneId" class="border rounded px-3 py-2 text-sm w-full">
                    <option value="">Select zone...</option>
                    @foreach ($zones as $z)
                        <option value="{{ $z->id }}">{{ $z->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Module</label>
                <select wire:model="module" class="border rounded px-3 py-2 text-sm w-full">
                    @foreach ($modules as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-3">
            <input type="text" wire:model="name" placeholder="Store name" class="border rounded px-3 py-2 text-sm">
            <input type="text" wire:model="addressLine" placeholder="Address" class="border rounded px-3 py-2 text-sm">
        </div>

        <div class="grid grid-cols-3 gap-4 mb-3">
            <input type="number" step="any" wire:model="lat" placeholder="Lat" class="border rounded px-3 py-2 text-sm">
            <input type="number" step="any" wire:model="lng" placeholder="Lng" class="border rounded px-3 py-2 text-sm">
            <input type="text" wire:model="phone" placeholder="Phone" class="border rounded px-3 py-2 text-sm">
        </div>

        <x-ui.button wire:click="createStore">Create Store</x-ui.button>
    </x-ui.card>

    <h1 class="text-2xl font-bold mb-4">Stores</h1>

    <div class="mb-4 flex gap-3">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name..." class="border rounded px-3 py-2 text-sm w-96">
        <select wire:model.live="moduleFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All modules</option>
            @foreach ($modules as $slug => $label)
                <option value="{{ $slug }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $stores->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Module</th>
                <th class="px-4 py-2">Owner</th>
                <th class="px-4 py-2">Open</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($stores as $s)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $s->name }}</td>
                    <td class="px-4 py-2">{{ \App\Support\Modules::label($s->module) }}</td>
                    <td class="px-4 py-2">{{ $s->provider?->user?->name }}</td>
                    <td class="px-4 py-2">
                        @if ($s->is_open) <x-ui.badge color="green">Open</x-ui.badge> @else <x-ui.badge color="gray">Closed</x-ui.badge> @endif
                    </td>
                    <td class="px-4 py-2">
                        @if ($s->is_active) <x-ui.badge color="green">Active</x-ui.badge> @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                    </td>
                    <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="editStore({{ $s->id }})">Edit</x-ui.button></td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>

    <x-ui.modal :show="$showEditModal" title="Edit Store">
        <input type="text" wire:model="editName" class="border rounded px-3 py-2 text-sm w-full mb-3">
        <label class="flex items-center gap-2 text-sm mb-2">
            <input type="checkbox" wire:model="editIsActive"> Active
        </label>
        <label class="flex items-center gap-2 text-sm mb-2">
            <input type="checkbox" wire:model="editIsOpen"> Open right now
        </label>
        <label class="flex items-center gap-2 text-sm mb-3">
            <input type="checkbox" wire:model="editPrescriptionRequired"> Requires prescription upload (Pharmacy)
        </label>
        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeEditModal">Cancel</x-ui.button>
            <x-ui.button wire:click="saveEdit">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
