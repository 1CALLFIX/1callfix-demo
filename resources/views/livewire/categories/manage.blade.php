<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <a href="{{ route('admin.services.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Services</a>
            <h1 class="text-2xl font-bold mt-1">Categories</h1>
        </div>
        <div class="flex gap-2 text-sm">
            <x-ui.button variant="secondary" size="sm" wire:click="exportCategoriesCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
            <x-ui.button variant="secondary" size="sm" wire:click="exportCategories" title="Full catalog backup, xlsx — re-importable via Import below">Export Catalog (xlsx)</x-ui.button>
            <x-ui.button variant="secondary" size="sm" wire:click="toggleCategoriesImport">
                {{ $showCategoriesImport ? 'Cancel Import' : 'Import' }}
            </x-ui.button>
        </div>
    </div>

    <x-catalog-tabs current="categories" />

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    @if ($showCategoriesImport)
        <x-import-panel label="Categories"
            file-model="categoriesImportFile"
            validate-method="validateCategoriesImport"
            commit-method="commitCategoriesImport"
            cancel-method="toggleCategoriesImport"
            template-method="downloadCategoriesTemplate"
            deactivate-missing-model="categoriesDeactivateMissing"
            :row-errors="$categoriesImportErrors"
            :rows="$categoriesImportRows"
            :message="$categoriesImportMessage"
            :run="$categoriesImportRun" />
    @endif

    {{-- Add New — one line, pinned at the top, list updates live below it. --}}
    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">Add New Category</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-28">
                <label class="block text-xs font-medium mb-1">Icon <span class="text-red-500">*</span></label>
                {{-- Click-to-upload: the real file input is hidden and driven by
                     this label, so the whole tile is the click target. --}}
                <label class="relative flex items-center justify-center w-full h-[38px] border border-dashed rounded cursor-pointer hover:bg-gray-50 overflow-hidden"
                       style="background-color: {{ $color ?: '#F9FAFB' }}">
                    <input type="file" wire:model="iconFile" accept="image/png,image/jpeg" class="sr-only">
                    @if ($iconFile)
                        <img src="{{ $iconFile->temporaryUrl() }}" alt="" class="h-full w-full object-contain">
                    @else
                        <span class="text-[11px] text-gray-500" wire:loading.remove wire:target="iconFile">PNG / JPEG</span>
                    @endif
                    <span class="text-[11px] text-gray-500" wire:loading wire:target="iconFile">Uploading…</span>
                </label>
            </div>
            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" placeholder="e.g. AC Repair, Electrical" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Module <span class="text-red-500">*</span></label>
                <select wire:model="module" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach ($modules as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-28">
                <label class="block text-xs font-medium mb-1">Colour</label>
                <input type="color" wire:model="color" class="w-full border rounded px-1 py-1 h-[38px]">
            </div>
            <label class="inline-flex items-center gap-2 text-sm h-[38px]">
                <input type="checkbox" wire:model="isActive" class="rounded"> Active
            </label>
            <x-ui.button wire:click="save" class="h-[38px] px-6">+ Add</x-ui.button>
        </div>

        @if ($errors->hasAny(['name', 'iconFile', 'module', 'color']))
            <div class="mt-2 space-y-1">
                @error('iconFile') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('name') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('module') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('color') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
            </div>
        @endif
    </x-ui.card>

    {{-- List controls: reorder / search / filters / per-page --}}
    <div class="flex items-center gap-2 mb-3 flex-wrap">
        <button type="button" wire:click="toggleReorder"
                @class([
                    'px-3 py-2 border rounded text-sm',
                    'bg-slate-900 text-white border-slate-900' => $reorderMode,
                    'border-gray-300 hover:bg-gray-50' => ! $reorderMode,
                ])>Reorder</button>

        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search"
               class="border border-gray-300 rounded px-3 py-2 text-sm w-56">

        <button type="button" wire:click="$toggle('showFilters')"
                @class([
                    'px-3 py-2 border rounded text-sm inline-flex items-center gap-1',
                    'bg-slate-900 text-white border-slate-900' => $showFilters,
                    'border-gray-300 hover:bg-gray-50' => ! $showFilters,
                ])>
            Filters
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" />
            </svg>
        </button>

        <div class="ml-auto">
            <select wire:model.live="perPage" class="border border-gray-300 rounded px-3 py-2 text-sm">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
    </div>

    @if ($showFilters)
        <div class="bg-white rounded-lg shadow-sm p-3 mb-3 flex items-end gap-3 flex-wrap">
            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Module</label>
                <select wire:model.live="filterModule" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All modules</option>
                    @foreach ($modules as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Status</label>
                <select wire:model.live="filterActive" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
            </div>
            @if ($filterModule !== '' || $filterActive !== '' || $search !== '')
                <button type="button" wire:click="$set('filterModule', ''); $set('filterActive', ''); $set('search', '')"
                        class="text-xs text-blue-600 hover:underline pb-2">Clear all</button>
            @endif
        </div>
    @endif

    @if ($reorderMode)
        <div class="bg-indigo-50 border border-indigo-100 text-indigo-800 rounded p-3 mb-3 text-xs">
            Reorder mode: use the ↑ / ↓ arrows to set the order categories appear in the app. Changes save immediately.
            @if ($filterModule !== '' || $search !== '')
                <span class="font-medium">Rows are moving within the current search/filter.</span>
            @endif
        </div>
    @endif

    <x-ui.table>
        <x-slot:footer>{{ $categories->links() }}</x-slot:footer>
        <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 w-12">SL</th>
                    <th class="px-4 py-2 w-20">
                        <button type="button" wire:click="sortBy('id')" class="inline-flex items-center gap-1 hover:text-gray-800">
                            ID
                            @if ($sortField === 'id')
                                <span class="text-gray-800">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-2">Icon</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Module</th>
                    <th class="px-4 py-2">Subcategories</th>
                    <th class="px-4 py-2">Active</th>
                    <th class="px-4 py-2">Created At</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $i => $category)
                    <tr class="border-t hover:bg-gray-50" wire:key="cat-{{ $category->id }}">
                        <td class="px-4 py-2 text-gray-400">{{ $categories->firstItem() + $i }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $category->id }}</td>
                        <td class="px-4 py-2">
                            <span class="w-9 h-9 rounded border flex items-center justify-center overflow-hidden"
                                  style="background-color: {{ $category->color ?: '#F9FAFB' }}">
                                @if ($category->image_url)
                                    <img src="{{ $category->image_url }}" alt="" class="w-full h-full object-contain">
                                @else
                                    {{-- Legacy rows imported before icons were uploads --}}
                                    <span class="text-base">{{ $category->icon }}</span>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-2 font-medium">{{ $category->name }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ \App\Support\Modules::label($category->module) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $category->subcategories_count }}</td>
                        <td class="px-4 py-2">
                            @if ($category->is_active)
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5 text-green-600" title="Active">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5 text-red-500" title="Inactive">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $category->created_at?->format('d M Y') }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-1.5 justify-end">
                                @if ($reorderMode)
                                    <button type="button" wire:click="moveUp({{ $category->id }})"
                                            class="w-8 h-8 rounded flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700"
                                            title="Move up">↑</button>
                                    <button type="button" wire:click="moveDown({{ $category->id }})"
                                            class="w-8 h-8 rounded flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700"
                                            title="Move down">↓</button>
                                @endif

                                <button type="button" wire:click="edit({{ $category->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-slate-700 hover:bg-slate-800 text-white" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="toggleCategory({{ $category->id }})"
                                        wire:loading.attr="disabled" wire:target="toggleCategory({{ $category->id }})"
                                        @class([
                                            'w-8 h-8 rounded flex items-center justify-center text-white',
                                            'bg-red-500 hover:bg-red-600' => $category->is_active,
                                            'bg-green-500 hover:bg-green-600' => ! $category->is_active,
                                        ])
                                        title="{{ $category->is_active ? 'Deactivate' : 'Activate' }}">
                                    @if ($category->is_active)
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    @endif
                                </button>

                                <button type="button" wire:click="confirmDelete({{ $category->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-red-100 hover:bg-red-200 text-red-600" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">
                        @if ($search !== '' || $filterModule !== '' || $filterActive !== '')
                            No categories match your search or filters.
                        @else
                            No categories yet. Add your first one above.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
    </x-ui.table>

    {{-- Edit modal — a Livewire boolean flag, not a separate route. --}}
    <x-ui.modal :show="$showEditModal" title="Edit Category" onClose="closeEditModal" maxWidth="lg">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="editName" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Icon <span class="text-red-500">*</span></label>
                            <div class="flex items-center gap-3">
                                <span class="w-16 h-16 rounded border flex items-center justify-center overflow-hidden shrink-0"
                                      style="background-color: {{ $editColor ?: '#F9FAFB' }}">
                                    @if ($editIconFile)
                                        <img src="{{ $editIconFile->temporaryUrl() }}" alt="" class="h-full w-full object-contain">
                                    @elseif ($this->editExistingImageUrl)
                                        <img src="{{ $this->editExistingImageUrl }}" alt="" class="h-full w-full object-contain">
                                    @else
                                        <span class="text-[11px] text-gray-400">None</span>
                                    @endif
                                </span>
                                <label class="px-3 py-1.5 border border-blue-400 text-blue-600 rounded text-xs cursor-pointer hover:bg-blue-50">
                                    <input type="file" wire:model="editIconFile" accept="image/png,image/jpeg" class="sr-only">
                                    <span wire:loading.remove wire:target="editIconFile">Change</span>
                                    <span wire:loading wire:target="editIconFile">Uploading…</span>
                                </label>
                            </div>
                            @error('editIconFile') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Colour</label>
                            <input type="color" wire:model.live="editColor" class="border rounded px-1 py-1 h-[38px] w-full">
                            <p class="text-xs text-gray-400 mt-1">Tile background behind the icon.</p>
                            @error('editColor') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Module <span class="text-red-500">*</span></label>
                        <select wire:model="editModule" class="w-full border rounded px-3 py-2 text-sm">
                            @foreach ($modules as $slug => $label)
                                <option value="{{ $slug }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('editModule') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="editIsActive" class="rounded"> Active
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <x-ui.button variant="secondary" wire:click="closeEditModal">Close</x-ui.button>
                    <x-ui.button wire:click="update">Update</x-ui.button>
                </div>
    </x-ui.modal>

    {{-- Delete confirmation --}}
    <x-ui.modal :show="(bool) $confirmingDeleteId" title="Delete category?" onClose="cancelDelete">
                @if ($deleteBlockedReason)
                    <p class="text-sm text-gray-600 mb-4">{{ $deleteBlockedReason }}</p>
                    <div class="flex justify-end">
                        <x-ui.button variant="secondary" wire:click="cancelDelete">Close</x-ui.button>
                    </div>
                @else
                    <p class="text-sm text-gray-600 mb-4">This can't be undone.</p>
                    <div class="flex justify-end gap-2">
                        <x-ui.button variant="secondary" wire:click="cancelDelete">Cancel</x-ui.button>
                        <x-ui.button variant="danger" wire:click="deleteCategory">Delete</x-ui.button>
                    </div>
                @endif
    </x-ui.modal>
</div>
