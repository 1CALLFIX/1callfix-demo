<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Products</h1>
        <div class="flex gap-2 text-sm">
            <x-ui.button variant="secondary" size="sm" wire:click="exportProductsCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
            <x-ui.button variant="secondary" size="sm" wire:click="exportProductsTemplate" title="Column template for import, xlsx">Download Template</x-ui.button>
            <x-ui.button variant="secondary" size="sm" wire:click="toggleProductsImport">
                {{ $showProductsImport ? 'Cancel Import' : 'Import' }}
            </x-ui.button>
        </div>
    </div>

    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    @if ($showProductsImport)
        <x-import-panel label="Products"
            file-model="productsImportFile"
            validate-method="validateProductsImport"
            commit-method="commitProductsImport"
            cancel-method="toggleProductsImport"
            template-method="exportProductsTemplate"
            deactivate-missing-model="productsDeactivateMissing"
            :errors="$productsImportErrors"
            :rows="$productsImportRows"
            :message="$productsImportMessage"
            :run="$productsImportRun" />
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-lg font-bold mb-3">New Product</h2>

        <div class="grid grid-cols-2 gap-4 mb-3">
            <select wire:model="storeId" class="border rounded px-3 py-2 text-sm">
                <option value="">Select store...</option>
                @foreach ($stores as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </select>
            <select wire:model="marketplaceCategoryId" class="border rounded px-3 py-2 text-sm">
                <option value="">No category</option>
                @foreach ($categories as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-3">
            <input type="text" wire:model="name" placeholder="Product name" class="border rounded px-3 py-2 text-sm">
            <input type="number" step="0.01" wire:model="price" placeholder="Price" class="border rounded px-3 py-2 text-sm">
            <input type="number" wire:model="stock" placeholder="Stock" class="border rounded px-3 py-2 text-sm">
        </div>

        <x-ui.button wire:click="createProduct">Create Product</x-ui.button>
    </x-ui.card>

    <h1 class="text-2xl font-bold mb-4">Products</h1>

    <div class="mb-4 flex gap-3">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name..." class="border rounded px-3 py-2 text-sm w-96">
        <select wire:model.live="storeIdFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All stores</option>
            @foreach ($stores as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $products->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Store</th>
                <th class="px-4 py-2">Price</th>
                <th class="px-4 py-2">Stock</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($products as $p)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $p->name }}</td>
                    <td class="px-4 py-2">{{ $p->store?->name }}</td>
                    <td class="px-4 py-2">{{ number_format($p->price, 2) }}</td>
                    <td class="px-4 py-2">{{ $p->stock }}</td>
                    <td class="px-4 py-2">
                        @if (! $p->is_approved) <x-ui.badge color="amber">Pending approval</x-ui.badge>
                        @elseif ($p->is_active) <x-ui.badge color="green">Active</x-ui.badge>
                        @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                    </td>
                    <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="editProduct({{ $p->id }})">Edit</x-ui.button></td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>

    <x-ui.modal :show="$showEditModal" title="Edit Product" maxWidth="2xl">
        <div class="grid grid-cols-3 gap-4 mb-4">
            <input type="text" wire:model="editName" placeholder="Name" class="border rounded px-3 py-2 text-sm">
            <input type="number" step="0.01" wire:model="editPrice" placeholder="Price" class="border rounded px-3 py-2 text-sm">
            <input type="number" wire:model="editStock" placeholder="Base stock" class="border rounded px-3 py-2 text-sm">
        </div>
        <label class="flex items-center gap-2 text-sm mb-2">
            <input type="checkbox" wire:model="editIsActive"> Active
        </label>
        <label class="flex items-center gap-2 text-sm mb-4">
            <input type="checkbox" wire:model="editIsApproved"> Approved for public listing
        </label>
        <x-ui.button wire:click="saveEdit">Save</x-ui.button>

        <hr class="my-4">

        <h3 class="font-bold text-sm mb-2">Variants</h3>
        @if ($this->editingProduct)
            <table class="w-full text-sm mb-3">
                <thead class="text-left text-gray-500">
                    <tr><th class="py-1">Name</th><th class="py-1">Price override</th><th class="py-1">Stock</th></tr>
                </thead>
                <tbody>
                    @foreach ($this->editingProduct->variants as $v)
                        <tr class="border-t">
                            <td class="py-1">{{ $v->name }}</td>
                            <td class="py-1">{{ $v->price_override !== null ? number_format($v->price_override, 2) : '—' }}</td>
                            <td class="py-1">{{ $v->stock }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="grid grid-cols-3 gap-3 mb-2">
            <input type="text" wire:model="newVariantName" placeholder="Variant name (e.g. Large / Red)" class="border rounded px-3 py-2 text-sm">
            <input type="number" step="0.01" wire:model="newVariantPriceOverride" placeholder="Price override (optional)" class="border rounded px-3 py-2 text-sm">
            <input type="number" wire:model="newVariantStock" placeholder="Stock" class="border rounded px-3 py-2 text-sm">
        </div>
        <x-ui.button variant="secondary" wire:click="addVariant">Add Variant</x-ui.button>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeEditModal">Close</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
