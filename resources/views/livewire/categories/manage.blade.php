<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <a href="{{ route('admin.services.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Services</a>
            <h1 class="text-2xl font-bold mt-1">Categories</h1>
        </div>
        <div class="flex gap-2 text-sm">
            <button type="button" wire:click="exportCategories" class="px-3 py-1.5 border border-gray-300 rounded text-xs hover:bg-gray-50">Export</button>
            <button type="button" wire:click="toggleCategoriesImport" class="px-3 py-1.5 border border-gray-300 rounded text-xs hover:bg-gray-50">
                {{ $showCategoriesImport ? 'Cancel Import' : 'Import' }}
            </button>
        </div>
    </div>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    @if ($showCategoriesImport)
        <x-import-panel label="Categories"
            file-model="categoriesImportFile"
            validate-method="validateCategoriesImport"
            commit-method="commitCategoriesImport"
            cancel-method="toggleCategoriesImport"
            template-method="downloadCategoriesTemplate"
            :errors="$categoriesImportErrors"
            :rows="$categoriesImportRows"
            :message="$categoriesImportMessage" />
    @endif

    {{-- Add New — pinned at the top, list updates live below it, no redirect. --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold mb-3">Add New Category</h2>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium mb-1">Name</label>
                <input type="text" wire:model="name" placeholder="e.g. AC Repair, Electrical" class="w-full border rounded px-3 py-2 text-sm">
                @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Icon (optional)</label>
                <input type="text" wire:model="icon" placeholder="emoji or icon class" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Image URL (optional)</label>
                <input type="text" wire:model="image" placeholder="https://…" class="w-full border rounded px-3 py-2 text-sm">
                @error('image') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Sort Order</label>
                <input type="number" wire:model="sortOrder" class="w-full border rounded px-3 py-2 text-sm">
                @error('sortOrder') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center justify-between mt-3">
            <div>
                <label class="block text-xs font-medium mb-1">Description (optional)</label>
                <textarea wire:model="description" rows="1" class="w-full md:w-96 border rounded px-3 py-2 text-sm"></textarea>
            </div>
            <div class="flex items-center gap-4">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="isActive" class="rounded"> Active
                </label>
                <button wire:click="save" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">
                    + Add Category
                </button>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Image</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Subcategories</th>
                    <th class="px-4 py-2">Services</th>
                    <th class="px-4 py-2">Active</th>
                    <th class="px-4 py-2">Created At</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2">
                            @if ($category->image)
                                <img src="{{ $category->image }}" alt="" class="w-8 h-8 rounded object-cover">
                            @elseif ($category->icon)
                                <span class="text-gray-400">{{ $category->icon }}</span>
                            @else
                                <span class="text-gray-300">&mdash;</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 font-medium">{{ $category->name }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $category->subcategories_count }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $category->services_count }}</td>
                        <td class="px-4 py-2">
                            <button type="button" wire:click="toggleCategory({{ $category->id }})"
                                    wire:loading.attr="disabled" wire:target="toggleCategory({{ $category->id }})"
                                    title="Click to {{ $category->is_active ? 'deactivate' : 'activate' }}"
                                    @class([
                                        'px-2 py-1 rounded text-xs font-medium cursor-pointer',
                                        'bg-green-100 text-green-700 hover:bg-green-200' => $category->is_active,
                                        'bg-gray-100 text-gray-700 hover:bg-gray-200' => ! $category->is_active,
                                    ])>{{ $category->is_active ? 'Active' : 'Inactive' }}</button>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $category->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-2">
                            <button type="button" wire:click="edit({{ $category->id }})" class="text-blue-600 hover:underline">Edit</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No categories yet. Add your first one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Edit modal — a Livewire boolean flag, not a separate route. --}}
    @if ($showEditModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4" wire:click.self="closeEditModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">Edit Category</h3>
                    <button type="button" wire:click="closeEditModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Name</label>
                        <input type="text" wire:model="editName" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Icon (optional)</label>
                            <input type="text" wire:model="editIcon" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Sort Order</label>
                            <input type="number" wire:model="editSortOrder" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editSortOrder') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-1">Image URL (optional)</label>
                            <input type="text" wire:model="editImage" placeholder="https://…" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editImage') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        @if ($editImage)
                            <img src="{{ $editImage }}" alt="" class="w-12 h-12 rounded object-cover border">
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Description (optional)</label>
                        <textarea wire:model="editDescription" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="editIsActive" class="rounded"> Active
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <button type="button" wire:click="closeEditModal" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Close</button>
                    <button type="button" wire:click="update" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Update</button>
                </div>
            </div>
        </div>
    @endif
</div>
