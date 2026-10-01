<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-lg font-bold mb-3">New Add-On</h2>

        <div class="grid grid-cols-3 gap-4 mb-3">
            <select wire:model="storeId" class="border rounded px-3 py-2 text-sm">
                <option value="">Select store...</option>
                @foreach ($stores as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </select>
            <input type="text" wire:model="name" placeholder="Add-on name (e.g. Extra cheese)" class="border rounded px-3 py-2 text-sm">
            <input type="number" step="0.01" wire:model="price" placeholder="Price" class="border rounded px-3 py-2 text-sm">
        </div>

        <x-ui.button wire:click="createAddOn">Create Add-On</x-ui.button>
    </x-ui.card>

    <h1 class="text-2xl font-bold mb-4">Add-Ons</h1>

    <div class="mb-4">
        <select wire:model.live="storeIdFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All stores</option>
            @foreach ($stores as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.archive-bars :bars="$archiveBars" />

    <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived']"
                      :active="$activeFilter" model="activeFilter" />

    <x-ui.table>
        <x-slot:footer>{{ $addOns->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <x-ui.sno-th />
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Store</th>
                <th class="px-4 py-2">Price</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($addOns as $a)
                <tr class="border-t">
                    <x-ui.sno :rows="$addOns" :loop="$loop" />
                    <td class="px-4 py-2">{{ $a->name }}</td>
                    <td class="px-4 py-2">{{ $a->store?->name }}</td>
                    <td class="px-4 py-2">{{ number_format($a->price, 2) }}</td>
                    <td class="px-4 py-2">
                        @if ($a->is_active) <x-ui.badge color="green">Active</x-ui.badge> @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                    </td>
                    <td class="px-4 py-2 text-right whitespace-nowrap"><div class="flex items-center justify-end gap-1">@unless ($a->trashed())<x-ui.button variant="ghost" wire:click="toggleActive({{ $a->id }})">Toggle</x-ui.button>@endunless<x-ui.row-actions :id="$a->id" :archived="$a->trashed()" :can-manage="$canManage" :can-force="$canForce" /></div></td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
</div>
