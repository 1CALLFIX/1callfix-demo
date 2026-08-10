<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Services</h1>
        <div class="flex gap-2 text-sm">
            <button type="button" wire:click="exportServices" class="px-3 py-1.5 border border-gray-300 rounded text-xs hover:bg-gray-50">Export</button>
            <button type="button" wire:click="toggleImport" class="px-3 py-1.5 border border-gray-300 rounded text-xs hover:bg-gray-50">
                {{ $showImport ? 'Cancel Import' : 'Import' }}
            </button>
        </div>
    </div>

    <x-catalog-tabs current="services" />

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    @if ($showImport)
        <x-import-panel label="Services"
            file-model="importFile"
            validate-method="validateServicesImport"
            commit-method="commitServicesImport"
            cancel-method="toggleImport"
            template-method="downloadTemplate"
            :errors="$importErrors"
            :rows="$importRows"
            :message="$importMessage" />
    @endif

    {{-- Add New — pinned at the top, list updates live below it. --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold mb-3">Add New Service</h2>

        <div class="flex flex-wrap items-end gap-3">
            <div class="w-28">
                <label class="block text-xs font-medium mb-1">Image</label>
                <label class="relative flex items-center justify-center w-full h-[38px] border border-dashed rounded cursor-pointer hover:bg-gray-50 overflow-hidden bg-gray-50">
                    <input type="file" wire:model="coverImageFile" accept="image/png,image/jpeg" class="sr-only">
                    @if ($coverImageFile)
                        <img src="{{ $coverImageFile->temporaryUrl() }}" alt="" class="h-full w-full object-contain">
                    @else
                        <span class="text-[11px] text-gray-500" wire:loading.remove wire:target="coverImageFile">PNG / JPEG</span>
                    @endif
                    <span class="text-[11px] text-gray-500" wire:loading wire:target="coverImageFile">Uploading…</span>
                </label>
            </div>

            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" placeholder="e.g. Split AC Gas Refill" class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Category <span class="text-red-500">*</span></label>
                <select wire:model.live="categoryId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Select</option>
                    @foreach ($categories->groupBy('module') as $moduleSlug => $group)
                        <optgroup label="{{ \App\Support\Modules::label($moduleSlug) }}">
                            @foreach ($group as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Subcategory</label>
                <select wire:model="subcategoryId" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $categoryId)>
                    <option value="">{{ $categoryId ? '-- Select --' : 'Pick a category first' }}</option>
                    @foreach ($this->subcategories as $sub)
                        <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-3">
            <label class="block text-xs font-medium mb-1">Description</label>
            {{-- Keyed on addFormKey: Trix owns this DOM, so blanking the PHP
                 property after a save isn't enough to clear what's on screen —
                 bumping the key makes Livewire replace the whole block with a
                 fresh, empty editor. wire:ignore for the same reason the edit
                 modal has it: Livewire's morphing would otherwise fight Trix
                 over cursor/toolbar state on every re-render. --}}
            <div wire:ignore wire:key="add-description-{{ $addFormKey }}">
                <input id="add-service-description-{{ $addFormKey }}" type="hidden" wire:model="description">
                <trix-editor input="add-service-description-{{ $addFormKey }}" class="w-full border rounded px-3 py-2 text-sm bg-white"></trix-editor>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 mt-3">
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Duration Type <span class="text-red-500">*</span></label>
                <select wire:model="priceType" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach (\App\Models\Service::PRICE_TYPE_LABELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-32">
                <label class="block text-xs font-medium mb-1">Price ({{ $currencySymbol }}) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" wire:model="basePrice" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-36">
                <label class="block text-xs font-medium mb-1">Discount Price ({{ $currencySymbol }})</label>
                <input type="number" step="0.01" wire:model="discountPrice" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-32">
                <label class="block text-xs font-medium mb-1">Est. time (min) <span class="text-red-500">*</span></label>
                <input type="number" step="1" wire:model="durationEstimateMins" class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <div class="flex flex-col gap-1 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="locationRequired" class="rounded"> Location Required
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="ageRestriction" class="rounded"> Age Restriction
                </label>
            </div>

            <label class="inline-flex items-center gap-2 text-sm h-[38px]">
                <input type="checkbox" wire:model="isActive" class="rounded"> Active
            </label>

            <button wire:click="save" class="bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800 ml-auto">
                + Add
            </button>
        </div>

        @if ($ageRestriction)
            <p class="text-xs text-blue-700 mt-2">Customer will be informed they must be of legal age when buying this service.</p>
        @endif

        @if ($errors->hasAny(['name', 'categoryId', 'basePrice', 'discountPrice', 'priceType', 'durationEstimateMins', 'coverImageFile']))
            <div class="mt-2 space-y-1">
                @error('categoryId') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('name') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('basePrice') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('discountPrice') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('priceType') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('durationEstimateMins') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
                @error('coverImageFile') <p class="text-red-600 text-xs">{{ $message }}</p> @enderror
            </div>
        @endif

        @if ($categories->isEmpty())
            <p class="text-xs text-amber-700 bg-amber-50 rounded p-2 mt-3">
                No categories exist yet — <a href="{{ route('admin.categories.index') }}" class="underline">create one first</a>.
            </p>
        @endif
    </div>

    {{-- List controls --}}
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
            <div class="w-48">
                <label class="block text-xs font-medium mb-1">Module</label>
                <select wire:model.live="filterModule" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All modules</option>
                    @foreach ($modules as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Category</label>
                <select wire:model.live="filterCategory" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All categories</option>
                    @foreach ($categories->when($filterModule !== '', fn ($c) => $c->where('module', $filterModule)) as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Subcategory</label>
                <select wire:model.live="filterSubcategory" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $filterCategory)>
                    <option value="">{{ $filterCategory ? 'All subcategories' : 'Pick a category first' }}</option>
                    @foreach ($this->filterSubcategories as $sub)
                        <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-40">
                <label class="block text-xs font-medium mb-1">Status</label>
                <select wire:model.live="filterActive" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
            </div>
            @if ($filterModule !== '' || $filterCategory !== '' || $filterSubcategory !== '' || $filterActive !== '' || $search !== '')
                <button type="button" wire:click="$set('filterModule', ''); $set('filterCategory', ''); $set('filterSubcategory', ''); $set('filterActive', ''); $set('search', '')"
                        class="text-xs text-blue-600 hover:underline pb-2">Clear all</button>
            @endif
        </div>
    @endif

    @if ($reorderMode)
        <div class="bg-indigo-50 border border-indigo-100 text-indigo-800 rounded p-3 mb-3 text-xs">
            Reorder mode: use the ↑ / ↓ arrows to set the order services appear in the app. Changes save immediately.
            @if ($filterModule !== '' || $filterCategory !== '' || $filterSubcategory !== '' || $search !== '')
                <span class="font-medium">Rows are moving within the current search/filter.</span>
            @endif
        </div>
    @endif

    <div class="bg-white rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
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
                    <th class="px-4 py-2">Image</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Category / Sub</th>
                    <th class="px-4 py-2">Duration Type</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Discount Price</th>
                    <th class="px-4 py-2">Active</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($services as $i => $service)
                    <tr class="border-t hover:bg-gray-50" wire:key="svc-{{ $service->id }}">
                        <td class="px-4 py-2 text-gray-400">{{ $services->firstItem() + $i }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $service->id }}</td>
                        <td class="px-4 py-2">
                            <span class="w-9 h-9 rounded border flex items-center justify-center overflow-hidden bg-gray-50">
                                @if ($service->cover_image_url)
                                    <img src="{{ $service->cover_image_url }}" alt="" class="w-full h-full object-contain">
                                @else
                                    <span class="text-[10px] text-gray-300">—</span>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-2 font-medium">{{ $service->name }}</td>
                        <td class="px-4 py-2 text-gray-500">
                            {{ $service->category?->name ?? '—' }}
                            @if ($service->subcategory)
                                <span class="block text-xs text-gray-400">{{ $service->subcategory->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">
                            {{ $service->price_type_label }}
                            <span class="block text-xs text-gray-400">{{ $service->duration_estimate_mins }} min</span>
                        </td>
                        <td class="px-4 py-2">{{ $currencySymbol }}{{ number_format($service->base_price, 2) }}</td>
                        <td class="px-4 py-2">
                            @if ($service->discount_price)
                                <span class="text-green-700 font-medium">{{ $currencySymbol }}{{ number_format($service->discount_price, 2) }}</span>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($service->is_active)
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5 text-green-600" title="Active">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5 text-red-500" title="Inactive">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-1.5 justify-end">
                                @if ($reorderMode)
                                    <button type="button" wire:click="moveUp({{ $service->id }})"
                                            class="w-8 h-8 rounded flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700"
                                            title="Move up">↑</button>
                                    <button type="button" wire:click="moveDown({{ $service->id }})"
                                            class="w-8 h-8 rounded flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700"
                                            title="Move down">↓</button>
                                @endif

                                <button type="button" wire:click="view({{ $service->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-amber-500 hover:bg-amber-600 text-white" title="View details">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="edit({{ $service->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-slate-700 hover:bg-slate-800 text-white" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="toggleActive({{ $service->id }})"
                                        wire:loading.attr="disabled" wire:target="toggleActive({{ $service->id }})"
                                        @class([
                                            'w-8 h-8 rounded flex items-center justify-center text-white',
                                            'bg-red-500 hover:bg-red-600' => $service->is_active,
                                            'bg-green-500 hover:bg-green-600' => ! $service->is_active,
                                        ])
                                        title="{{ $service->is_active ? 'Deactivate' : 'Activate' }}">
                                    @if ($service->is_active)
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    @endif
                                </button>

                                <button type="button" wire:click="confirmDelete({{ $service->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-red-100 hover:bg-red-200 text-red-600" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-6 text-center text-gray-400">
                        @if ($search !== '' || $filterModule !== '' || $filterCategory !== '' || $filterSubcategory !== '' || $filterActive !== '')
                            No services match your search or filters.
                        @else
                            No services yet. Add your first one above.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $services->links() }}
    </div>

    {{-- View details modal — read-only, the eye icon. --}}
    @if ($showViewModal && $this->viewingService)
        @php $svc = $this->viewingService; @endphp
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4 overflow-y-auto" wire:click.self="closeViewModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-xl p-6 my-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">Service Details</h3>
                    <button type="button" wire:click="closeViewModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="space-y-4 text-sm">
                    <div>
                        <p class="text-xs text-gray-500">Name</p>
                        <p class="font-semibold">{{ $svc->name }}</p>
                    </div>

                    @if ($svc->description)
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Description</p>
                            {{-- Rich text authored by a super_admin through Trix on this
                                 same screen; rendered as HTML so the formatting shows.
                                 Not user-supplied content. --}}
                            <div class="prose prose-sm max-w-none border rounded p-3 bg-gray-50">{!! $svc->description !!}</div>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-500">Category</p>
                            <p>{{ $svc->category?->name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Subcategory</p>
                            <p>{{ $svc->subcategory?->name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Price</p>
                            <p class="font-medium">{{ $currencySymbol }}{{ number_format($svc->base_price, 2) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Discount Price</p>
                            <p>{{ $svc->discount_price ? $currencySymbol.number_format($svc->discount_price, 2) : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Duration Type</p>
                            <p>{{ $svc->price_type_label }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Est. time</p>
                            <p>{{ $svc->duration_estimate_mins }} min</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-6">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Location Required</p>
                            <x-yes-no :value="$svc->location_required" />
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Age Restriction</p>
                            <x-yes-no :value="$svc->age_restriction" />
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Active</p>
                            <x-yes-no :value="$svc->is_active" />
                        </div>
                    </div>

                    @if ($svc->cover_image_url)
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Image</p>
                            <img src="{{ $svc->cover_image_url }}" alt="" class="max-h-48 rounded border">
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <button type="button" wire:click="edit({{ $svc->id }})" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Edit</button>
                    <button type="button" wire:click="closeViewModal" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Close</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit modal --}}
    @if ($showEditModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4 overflow-y-auto" wire:click.self="closeEditModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl p-6 my-8" wire:key="edit-service-{{ $editServiceId }}">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">Edit Service</h3>
                    <button type="button" wire:click="closeEditModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Category <span class="text-red-500">*</span></label>
                            <select wire:model.live="editCategoryId" class="w-full border rounded px-3 py-2 text-sm">
                                @foreach ($categories->groupBy('module') as $moduleSlug => $group)
                                    <optgroup label="{{ \App\Support\Modules::label($moduleSlug) }}">
                                        @foreach ($group as $cat)
                                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('editCategoryId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Subcategory</label>
                            <select wire:model="editSubcategoryId" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $editCategoryId)>
                                <option value="">-- Select --</option>
                                @foreach ($this->editSubcategories as $sub)
                                    <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="editName" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Description</label>
                        {{-- wire:ignore: Trix manages this DOM itself (cursor position,
                             toolbar state) and Livewire's morph-on-update — triggered by
                             the Category select's wire:model.live above — would fight it.
                             The hidden input's wire:model still works under wire:ignore:
                             that only stops DOM morphing, not the input-event listener
                             Livewire already attached, and Trix fires a real 'input' event
                             on every change. The modal's wire:key is what rebuilds this
                             with fresh content when a different service is opened. --}}
                        <div wire:ignore>
                            <input id="service-description-{{ $editServiceId }}" type="hidden" wire:model="editDescription" value="{{ $editDescription }}">
                            <trix-editor input="service-description-{{ $editServiceId }}" class="w-full border rounded px-3 py-2 text-sm bg-white"></trix-editor>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Image</label>
                        <div class="flex items-center gap-3">
                            <span class="w-16 h-16 rounded border flex items-center justify-center overflow-hidden shrink-0 bg-gray-50">
                                @if ($editCoverImageFile)
                                    <img src="{{ $editCoverImageFile->temporaryUrl() }}" alt="" class="h-full w-full object-contain">
                                @elseif ($this->editExistingImageUrl)
                                    <img src="{{ $this->editExistingImageUrl }}" alt="" class="h-full w-full object-contain">
                                @else
                                    <span class="text-[11px] text-gray-400">None</span>
                                @endif
                            </span>
                            <label class="px-3 py-1.5 border border-blue-400 text-blue-600 rounded text-xs cursor-pointer hover:bg-blue-50">
                                <input type="file" wire:model="editCoverImageFile" accept="image/png,image/jpeg" class="sr-only">
                                <span wire:loading.remove wire:target="editCoverImageFile">Click/Tap to select media</span>
                                <span wire:loading wire:target="editCoverImageFile">Uploading…</span>
                            </label>
                        </div>
                        @error('editCoverImageFile') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-4 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Duration Type <span class="text-red-500">*</span></label>
                            <select wire:model="editPriceType" class="w-full border rounded px-3 py-2 text-sm">
                                @foreach (\App\Models\Service::PRICE_TYPE_LABELS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Price ({{ $currencySymbol }}) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" wire:model="editBasePrice" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editBasePrice') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Discount ({{ $currencySymbol }})</label>
                            <input type="number" step="0.01" wire:model="editDiscountPrice" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editDiscountPrice') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Est. time (min) <span class="text-red-500">*</span></label>
                            <input type="number" step="1" wire:model="editDurationEstimateMins" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editDurationEstimateMins') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-6 text-sm">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model="editLocationRequired" class="rounded"> Location Required
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model.live="editAgeRestriction" class="rounded"> Age Restriction
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model="editIsActive" class="rounded"> Active
                        </label>
                    </div>

                    @if ($editAgeRestriction)
                        <p class="text-xs text-blue-700">Customer will be informed they must be of legal age when buying this service.</p>
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <button type="button" wire:click="closeEditModal" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Close</button>
                    <button type="button" wire:click="update" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Update</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4" wire:click.self="cancelDelete">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
                <h3 class="text-lg font-bold mb-2">Delete service?</h3>

                @if ($deleteWarning)
                    <p class="text-sm text-gray-600 mb-4">{{ $deleteWarning }}</p>
                @else
                    <p class="text-sm text-gray-600 mb-4">It will be removed from the catalog.</p>
                @endif

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelDelete" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Cancel</button>
                    <button type="button" wire:click="deleteService" class="bg-red-600 text-white px-6 py-2 rounded text-sm font-medium hover:bg-red-700">Delete</button>
                </div>
            </div>
        </div>
    @endif
</div>
