<div>
    <h1 class="text-2xl font-bold mb-4">Zones</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    @unless ($mapsConfigured)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded p-3 mb-4 text-sm">
            <span class="font-medium">Google Maps key isn't configured.</span>
            The boundary map won't load, so zones can't be created or edited until
            <span class="font-mono text-xs">GOOGLE_MAPS_API_KEY</span> is set in the server's <span class="font-mono text-xs">.env</span>.
        </div>
    @endunless

    {{-- Add New — fields left, boundary map right, mirroring the reference panel. --}}
    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">Add New Zone</h2>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Franchise <span class="text-red-500">*</span></label>
                    <select wire:model="franchiseId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Select franchise</option>
                        @foreach ($franchises as $franchise)
                            <option value="{{ $franchise->id }}">{{ $franchise->name }}@if($franchise->code) ({{ $franchise->code }})@endif</option>
                        @endforeach
                    </select>
                    @error('franchiseId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium mb-1">Zone name <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="name" placeholder="e.g. Nellore Zone 1" class="w-full border rounded px-3 py-2 text-sm">
                    @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 mt-1">The zone code is generated from this automatically.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium mb-1">Default dispatch radius (km) <span class="text-red-500">*</span></label>
                    <input type="number" step="1" wire:model="defaultDispatchRadiusKm" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">Used by the dispatch engine's distance calc, not by the boundary drawn here.</p>
                    @error('defaultDispatchRadiusKm') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-between pt-1">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="isActive" class="rounded"> Active
                    </label>
                    <x-ui.button wire:click="save" class="h-[38px] px-6">+ Add Zone</x-ui.button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium mb-1">Boundary <span class="text-red-500">*</span></label>
                <p class="text-xs text-gray-400 mb-2">Click <strong>Start Drawing</strong>, place at least 3 points on the map, then <strong>Finish Boundary</strong>.</p>
                <x-zone-map :component-id="$this->getId()" model="boundaryPolygonJson" :polygon="$boundaryPolygonJson" height="300px" />
                @error('boundaryPolygonJson') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </x-ui.card>

    {{-- List controls --}}
    <div class="flex items-center gap-2 mb-3 flex-wrap">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search name or code"
               class="border border-gray-300 rounded px-3 py-2 text-sm w-64">

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
                <label class="block text-xs font-medium mb-1">Franchise</label>
                <select wire:model.live="filterFranchise" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All franchises</option>
                    @foreach ($franchises as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
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
            @if ($filterFranchise !== '' || $filterActive !== '' || $search !== '')
                <button type="button" wire:click="$set('filterFranchise', ''); $set('filterActive', ''); $set('search', '')"
                        class="text-xs text-blue-600 hover:underline pb-2">Clear all</button>
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
                    <th class="px-4 py-2">
                        <button type="button" wire:click="sortBy('name')" class="inline-flex items-center gap-1 hover:text-gray-800">
                            Zone Name
                            @if ($sortField === 'name')
                                <span class="text-gray-800">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Franchise</th>
                    <th class="px-4 py-2">Providers</th>
                    <th class="px-4 py-2">Bookings</th>
                    <th class="px-4 py-2">Boundary</th>
                    <th class="px-4 py-2">Radius</th>
                    <th class="px-4 py-2">Active</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($zones as $i => $zone)
                    <tr class="border-t hover:bg-gray-50" wire:key="zone-{{ $zone->id }}">
                        <td class="px-4 py-2 text-gray-400">{{ $zones->firstItem() + $i }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $zone->id }}</td>
                        <td class="px-4 py-2 font-medium">{{ $zone->name }}</td>
                        <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $zone->code ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $zone->franchise?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $zone->providers_count }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $zone->bookings_count }}</td>
                        <td class="px-4 py-2 text-gray-500 text-xs">
                            @php $pts = is_array($zone->boundary_polygon) ? count($zone->boundary_polygon) : 0; @endphp
                            @if ($pts >= 3)
                                {{ $pts }} points
                            @else
                                <span class="text-amber-600">Not set</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $zone->default_dispatch_radius_km }} km</td>
                        <td class="px-4 py-2">
                            @if ($zone->is_active)
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
                                <button type="button" wire:click="view({{ $zone->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-amber-500 hover:bg-amber-600 text-white" title="View details">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="edit({{ $zone->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-slate-700 hover:bg-slate-800 text-white" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="toggleActive({{ $zone->id }})"
                                        wire:loading.attr="disabled" wire:target="toggleActive({{ $zone->id }})"
                                        @class([
                                            'w-8 h-8 rounded flex items-center justify-center text-white',
                                            'bg-red-500 hover:bg-red-600' => $zone->is_active,
                                            'bg-green-500 hover:bg-green-600' => ! $zone->is_active,
                                        ])
                                        title="{{ $zone->is_active ? 'Deactivate' : 'Activate' }}">
                                    @if ($zone->is_active)
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    @endif
                                </button>

                                <button type="button" wire:click="confirmDelete({{ $zone->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-red-100 hover:bg-red-200 text-red-600" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-6 text-center text-gray-400">
                        @if ($search !== '' || $filterFranchise !== '' || $filterActive !== '')
                            No zones match your search or filters.
                        @else
                            No zones yet. Add your first one above.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $zones->links() }}
    </div>

    {{-- View details modal --}}
    @if ($showViewModal && $this->viewingZone)
        @php $z = $this->viewingZone; @endphp
        <x-ui.modal :show="true" title="Zone Details" onClose="closeViewModal" maxWidth="lg">
                <div class="space-y-4 text-sm">
                    <div>
                        <p class="text-xs text-gray-500">Name</p>
                        <p class="font-semibold">{{ $z->display_name }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-500">Franchise</p>
                            <p>{{ $z->franchise?->name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Code</p>
                            <p class="font-mono text-xs">{{ $z->code ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Providers</p>
                            <p>{{ $z->providers_count }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Bookings</p>
                            <p>{{ $z->bookings_count }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Dispatch radius</p>
                            <p>{{ $z->default_dispatch_radius_km }} km</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Active</p>
                            <x-yes-no :value="$z->is_active" />
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Boundary points</p>
                            <p>{{ is_array($z->boundary_polygon) ? count($z->boundary_polygon) : 0 }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Centre</p>
                            <p class="font-mono text-xs">{{ $z->center_lat }}, {{ $z->center_lng }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <x-ui.button variant="secondary" wire:click="edit({{ $z->id }})">Edit</x-ui.button>
                    <x-ui.button wire:click="closeViewModal">Close</x-ui.button>
                </div>
        </x-ui.modal>
    @endif

    {{-- Edit modal — carries its own map instance, see components/zone-map.blade.php --}}
    @if ($showEditModal)
        <x-ui.modal :show="true" title="Edit Zone" onClose="closeEditModal" maxWidth="3xl">
            <div wire:key="edit-zone-{{ $editZoneId }}">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Franchise <span class="text-red-500">*</span></label>
                            <select wire:model="editFranchiseId" class="w-full border rounded px-3 py-2 text-sm">
                                @foreach ($franchises as $franchise)
                                    <option value="{{ $franchise->id }}">{{ $franchise->name }}@if($franchise->code) ({{ $franchise->code }})@endif</option>
                                @endforeach
                            </select>
                            @error('editFranchiseId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Zone name <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="editName" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Code</label>
                            <input type="text" value="{{ $editCode }}" disabled class="w-full border rounded px-3 py-2 text-sm bg-gray-50 text-gray-500 font-mono">
                            <p class="text-xs text-gray-400 mt-1">Auto-generated; change it in the database if you need something specific.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Default dispatch radius (km) <span class="text-red-500">*</span></label>
                            <input type="number" step="1" wire:model="editDefaultDispatchRadiusKm" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editDefaultDispatchRadiusKm') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>

                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="editIsActive" class="rounded"> Active
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Boundary <span class="text-red-500">*</span></label>
                        <x-zone-map :component-id="$this->getId()" model="editBoundaryPolygonJson" :polygon="$editBoundaryPolygonJson" height="280px" />
                        @error('editBoundaryPolygonJson') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <x-ui.button variant="secondary" wire:click="closeEditModal">Close</x-ui.button>
                    <x-ui.button wire:click="update">Update</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    {{-- Delete confirmation --}}
    <x-ui.modal :show="(bool) $confirmingDeleteId" title="Delete zone?" onClose="cancelDelete">
                @if ($deleteBlockedReason)
                    <p class="text-sm text-gray-600 mb-4">{{ $deleteBlockedReason }}</p>
                    <div class="flex justify-end">
                        <x-ui.button variant="secondary" wire:click="cancelDelete">Close</x-ui.button>
                    </div>
                @else
                    <p class="text-sm text-gray-600 mb-4">This can't be undone.</p>
                    <div class="flex justify-end gap-2">
                        <x-ui.button variant="secondary" wire:click="cancelDelete">Cancel</x-ui.button>
                        <x-ui.button variant="danger" wire:click="deleteZone">Delete</x-ui.button>
                    </div>
                @endif
    </x-ui.modal>
</div>
