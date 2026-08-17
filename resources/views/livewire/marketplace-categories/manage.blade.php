<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-600 mb-1">Module</label>
        <select wire:model.live="module" class="border rounded px-3 py-2 text-sm">
            @foreach ($modules as $slug => $label)
                <option value="{{ $slug }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.card class="mb-6">
        <h2 class="text-lg font-bold mb-3">New Category</h2>

        <div class="grid grid-cols-2 gap-4 mb-3">
            <input type="text" wire:model="name" placeholder="Category name" class="border rounded px-3 py-2 text-sm">
            <select wire:model="parentId" class="border rounded px-3 py-2 text-sm">
                <option value="">Top-level (no parent)</option>
                @foreach ($parentOptions as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </div>

        <x-ui.button wire:click="createCategory">Create Category</x-ui.button>
    </x-ui.card>

    <h1 class="text-2xl font-bold mb-4">Marketplace Categories</h1>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name..." class="border rounded px-3 py-2 text-sm w-96">
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $categories->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Parent</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($categories as $c)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $c->name }}</td>
                    <td class="px-4 py-2">{{ $c->parent?->name ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @if ($c->is_active) <x-ui.badge color="green">Active</x-ui.badge> @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                    </td>
                    <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="editCategory({{ $c->id }})">Edit</x-ui.button></td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>

    <x-ui.modal :show="$showEditModal" title="Edit Category">
        <input type="text" wire:model="editName" class="border rounded px-3 py-2 text-sm w-full mb-3">
        <label class="flex items-center gap-2 text-sm mb-3">
            <input type="checkbox" wire:model="editIsActive"> Active
        </label>
        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeEditModal">Cancel</x-ui.button>
            <x-ui.button wire:click="saveEdit">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
