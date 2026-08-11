@php
    $statusStyles = [
        'active' => 'bg-green-100 text-green-700',
        'inactive' => 'bg-gray-100 text-gray-600',
        'pending_setup' => 'bg-amber-100 text-amber-700',
    ];
@endphp

<div>
    <h1 class="text-2xl font-bold mb-4">Franchises</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    {{-- Add New — pinned at the top, list updates live below it. --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold mb-3">Add New Franchise</h2>

        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" placeholder="e.g. Nellore" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Country <span class="text-red-500">*</span></label>
                <select wire:model.live="countryId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Select…</option>
                    @foreach ($countries as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
                @error('countryId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">City <span class="text-red-500">*</span></label>
                <select wire:model="cityId" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $countryId)>
                    <option value="">{{ $countryId ? 'Select…' : 'Pick a country first' }}</option>
                    @foreach ($cities as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
                @error('cityId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="w-32">
                <label class="block text-xs font-medium mb-1">State</label>
                <input type="text" wire:model="state" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <button type="button" wire:click="toggleNewCountryForm" class="text-xs text-blue-600 hover:underline h-[38px]">
                {{ $showNewCountryForm ? 'Cancel' : '+ New country' }}
            </button>
            <button type="button" wire:click="toggleNewCityForm" class="text-xs text-blue-600 hover:underline h-[38px]">
                {{ $showNewCityForm ? 'Cancel' : '+ New city' }}
            </button>
        </div>

        @if ($showNewCountryForm)
            <div class="flex flex-wrap items-end gap-3 mt-3 bg-gray-50 border rounded p-3">
                <div class="w-44">
                    <input type="text" wire:model="newCountryName" placeholder="Country name" class="w-full border rounded px-3 py-2 text-sm">
                    @error('newCountryName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="w-24">
                    <input type="text" wire:model="newCountryCode" placeholder="Code (IN)" maxlength="2" class="w-full border rounded px-3 py-2 text-sm">
                    @error('newCountryCode') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="w-28">
                    <input type="text" wire:model="newCountryCurrencyCode" placeholder="Currency" maxlength="3" class="w-full border rounded px-3 py-2 text-sm">
                    @error('newCountryCurrencyCode') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="w-40">
                    <input type="text" wire:model="newCountryTimezone" placeholder="Timezone" class="w-full border rounded px-3 py-2 text-sm">
                    @error('newCountryTimezone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="createCountry" class="bg-slate-900 text-white px-4 h-[38px] rounded text-xs font-medium hover:bg-slate-800">
                    Create country
                </button>
            </div>
        @endif

        @if ($showNewCityForm)
            <div class="flex flex-wrap items-end gap-3 mt-3 bg-gray-50 border rounded p-3">
                <div class="w-56">
                    <input type="text" wire:model="newCityName" placeholder="City name" class="w-full border rounded px-3 py-2 text-sm">
                    @error('newCityName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="createCity" class="bg-slate-900 text-white px-4 h-[38px] rounded text-xs font-medium hover:bg-slate-800">
                    Create city
                </button>
            </div>
        @endif

        <div class="flex flex-wrap items-end gap-3 mt-3">
            <div class="w-48">
                <label class="block text-xs font-medium mb-1">Commission model <span class="text-red-500">*</span></label>
                <select wire:model="commissionModel" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="revenue_share">Revenue share</option>
                    <option value="flat_fee">Flat fee</option>
                    <option value="subscription_only">Subscription only</option>
                </select>
            </div>
            <div class="w-36">
                <label class="block text-xs font-medium mb-1">Commission value <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" wire:model="commissionValue" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-36">
                <label class="block text-xs font-medium mb-1">Platform fee (%) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" wire:model="platformFeePercent" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Status <span class="text-red-500">*</span></label>
                <select wire:model="status" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="pending_setup">Pending setup</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <button wire:click="save" class="bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800 ml-auto">
                + Add
            </button>
        </div>

        <p class="text-xs text-gray-400 mt-2">
            The franchise code is generated from the name automatically. New franchises start with the
            <span class="font-medium">Service</span> module on and the rest off — switch others on from Edit once they're built.
        </p>

        @if ($errors->any())
            <div class="mt-2 space-y-1">
                @foreach ($errors->all() as $error)
                    <p class="text-red-600 text-xs">{{ $error }}</p>
                @endforeach
            </div>
        @endif
    </div>

    {{-- List controls --}}
    <div class="flex items-center gap-2 mb-3 flex-wrap">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search name, code or city"
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
                <label class="block text-xs font-medium mb-1">Status</label>
                <select wire:model.live="filterStatus" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="pending_setup">Pending setup</option>
                </select>
            </div>
            @if ($filterStatus !== '' || $search !== '')
                <button type="button" wire:click="$set('filterStatus', ''); $set('search', '')"
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
                            Name
                            @if ($sortField === 'name')
                                <span class="text-gray-800">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">City</th>
                    <th class="px-4 py-2">Commission</th>
                    <th class="px-4 py-2">Zones</th>
                    <th class="px-4 py-2">Providers</th>
                    <th class="px-4 py-2">Bookings</th>
                    <th class="px-4 py-2">Modules</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($franchises as $i => $franchise)
                    <tr class="border-t hover:bg-gray-50" wire:key="franchise-{{ $franchise->id }}">
                        <td class="px-4 py-2 text-gray-400">{{ $franchises->firstItem() + $i }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $franchise->id }}</td>
                        <td class="px-4 py-2 font-medium">{{ $franchise->name }}</td>
                        <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $franchise->code ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">
                            {{ $franchise->city?->name ?? '—' }}
                            <span class="block text-xs text-gray-400">
                                {{ collect([$franchise->state, $franchise->country?->name])->filter()->implode(', ') }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-gray-500 text-xs">
                            {{ str_replace('_', ' ', $franchise->commission_model) }}
                            <span class="block text-gray-400">{{ $franchise->commission_value }} · fee {{ $franchise->platform_fee_percent }}%</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $franchise->zones_count }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $franchise->providers_count }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $franchise->bookings_count }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">
                            @php
                                $on = collect($toggleable)->filter(fn ($l, $slug) => $franchise->modules?->{$slug});
                            @endphp
                            <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-700">Service</span>
                            @if ($on->isNotEmpty())
                                <span class="text-gray-400">+{{ $on->count() }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <span @class(['px-2 py-1 rounded text-xs font-medium', $statusStyles[$franchise->status] ?? 'bg-gray-100 text-gray-600'])>
                                {{ str_replace('_', ' ', $franchise->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-1.5 justify-end">
                                <button type="button" wire:click="view({{ $franchise->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-amber-500 hover:bg-amber-600 text-white" title="View details">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="edit({{ $franchise->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-slate-700 hover:bg-slate-800 text-white" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="toggleStatus({{ $franchise->id }})"
                                        wire:loading.attr="disabled" wire:target="toggleStatus({{ $franchise->id }})"
                                        @class([
                                            'w-8 h-8 rounded flex items-center justify-center text-white',
                                            'bg-red-500 hover:bg-red-600' => $franchise->status === 'active',
                                            'bg-green-500 hover:bg-green-600' => $franchise->status !== 'active',
                                        ])
                                        title="{{ $franchise->status === 'active' ? 'Deactivate' : 'Activate' }}">
                                    @if ($franchise->status === 'active')
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    @endif
                                </button>

                                <button type="button" wire:click="confirmDelete({{ $franchise->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-red-100 hover:bg-red-200 text-red-600" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="px-4 py-6 text-center text-gray-400">
                        @if ($search !== '' || $filterStatus !== '')
                            No franchises match your search or filters.
                        @else
                            No franchises yet. Add your first one above.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $franchises->links() }}
    </div>

    {{-- View details modal --}}
    @if ($showViewModal && $this->viewingFranchise)
        @php $f = $this->viewingFranchise; @endphp
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4 overflow-y-auto" wire:click.self="closeViewModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6 my-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">Franchise Details</h3>
                    <button type="button" wire:click="closeViewModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="space-y-4 text-sm">
                    <div class="flex items-center gap-3">
                        <p class="font-semibold text-base">{{ $f->name }}</p>
                        <span class="font-mono text-xs text-gray-500">{{ $f->code }}</span>
                        <span @class(['px-2 py-0.5 rounded text-xs font-medium', $statusStyles[$f->status] ?? 'bg-gray-100'])>
                            {{ str_replace('_', ' ', $f->status) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-500">Location</p>
                            <p>{{ collect([$f->city?->name, $f->state, $f->country?->name])->filter()->implode(', ') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Commission</p>
                            <p>{{ str_replace('_', ' ', $f->commission_model) }} · {{ $f->commission_value }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Platform fee</p>
                            <p>{{ $f->platform_fee_percent }}%</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Zones / Providers / Bookings</p>
                            <p>{{ $f->zones_count }} · {{ $f->providers_count }} · {{ $f->bookings_count }}</p>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs text-gray-500 mb-1">Modules enabled</p>
                        <div class="flex flex-wrap gap-1.5">
                            <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700">Service</span>
                            @foreach ($toggleable as $slug => $label)
                                <span @class([
                                    'px-2 py-0.5 rounded text-xs',
                                    'bg-green-100 text-green-700' => $f->modules?->{$slug},
                                    'bg-gray-100 text-gray-400' => ! $f->modules?->{$slug},
                                ])>{{ $label }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <button type="button" wire:click="edit({{ $f->id }})" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Edit</button>
                    <button type="button" wire:click="closeViewModal" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Close</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit modal --}}
    @if ($showEditModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4 overflow-y-auto" wire:click.self="closeEditModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-2xl p-6 my-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">Edit Franchise</h3>
                    <button type="button" wire:click="closeEditModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="editName" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Code</label>
                            <input type="text" value="{{ $editCode }}" disabled class="w-full border rounded px-3 py-2 text-sm bg-gray-50 text-gray-500 font-mono">
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Country <span class="text-red-500">*</span></label>
                            <select wire:model.live="editCountryId" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Select…</option>
                                @foreach ($countries as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                            @error('editCountryId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">City <span class="text-red-500">*</span></label>
                            <select wire:model="editCityId" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $editCountryId)>
                                <option value="">{{ $editCountryId ? 'Select…' : 'Pick a country first' }}</option>
                                @foreach ($editCities as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                            @error('editCityId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">State</label>
                            <input type="text" wire:model="editState" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <button type="button" wire:click="toggleEditNewCountryForm" class="text-xs text-blue-600 hover:underline">
                            {{ $showEditNewCountryForm ? 'Cancel' : '+ New country' }}
                        </button>
                        <button type="button" wire:click="toggleEditNewCityForm" class="text-xs text-blue-600 hover:underline">
                            {{ $showEditNewCityForm ? 'Cancel' : '+ New city' }}
                        </button>
                    </div>

                    @if ($showEditNewCountryForm)
                        <div class="flex flex-wrap items-end gap-3 bg-gray-50 border rounded p-3">
                            <div class="w-44">
                                <input type="text" wire:model="editNewCountryName" placeholder="Country name" class="w-full border rounded px-3 py-2 text-sm">
                                @error('editNewCountryName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-24">
                                <input type="text" wire:model="editNewCountryCode" placeholder="Code (IN)" maxlength="2" class="w-full border rounded px-3 py-2 text-sm">
                                @error('editNewCountryCode') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-28">
                                <input type="text" wire:model="editNewCountryCurrencyCode" placeholder="Currency" maxlength="3" class="w-full border rounded px-3 py-2 text-sm">
                                @error('editNewCountryCurrencyCode') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-40">
                                <input type="text" wire:model="editNewCountryTimezone" placeholder="Timezone" class="w-full border rounded px-3 py-2 text-sm">
                                @error('editNewCountryTimezone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="createEditCountry" class="bg-slate-900 text-white px-4 h-[38px] rounded text-xs font-medium hover:bg-slate-800">
                                Create country
                            </button>
                        </div>
                    @endif

                    @if ($showEditNewCityForm)
                        <div class="flex flex-wrap items-end gap-3 bg-gray-50 border rounded p-3">
                            <div class="w-56">
                                <input type="text" wire:model="editNewCityName" placeholder="City name" class="w-full border rounded px-3 py-2 text-sm">
                                @error('editNewCityName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="createEditCity" class="bg-slate-900 text-white px-4 h-[38px] rounded text-xs font-medium hover:bg-slate-800">
                                Create city
                            </button>
                        </div>
                    @endif

                    <div class="grid grid-cols-4 gap-3">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium mb-1">Commission model <span class="text-red-500">*</span></label>
                            <select wire:model="editCommissionModel" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="revenue_share">Revenue share</option>
                                <option value="flat_fee">Flat fee</option>
                                <option value="subscription_only">Subscription only</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Value <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" wire:model="editCommissionValue" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editCommissionValue') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Fee (%) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" wire:model="editPlatformFeePercent" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editPlatformFeePercent') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Status <span class="text-red-500">*</span></label>
                        <select wire:model="editStatus" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="pending_setup">Pending setup</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="border-t pt-4 relative">
                        <label class="block text-sm font-medium mb-1">Owner</label>
                        <p class="text-xs text-gray-400 mb-2">Who this franchise's revenue share (see Commission Defaults) gets credited to, once a booking completes.</p>
                        <div class="flex items-center gap-2">
                            <input type="text" wire:model.live="editOwnerSearch" placeholder="Search name or phone..." class="flex-1 border rounded px-3 py-2 text-sm" autocomplete="off">
                            @if ($editOwnerUserId)
                                <button type="button" wire:click="clearEditOwner" class="text-xs text-red-600 hover:underline whitespace-nowrap">Clear</button>
                            @endif
                        </div>
                        @if ($editOwnerSearch && $this->matchingOwners->isNotEmpty() && ! $editOwnerUserId)
                            <div class="absolute z-10 bg-white border rounded shadow-sm mt-1 w-full max-h-48 overflow-y-auto">
                                @foreach ($this->matchingOwners as $u)
                                    <button type="button" wire:click="selectEditOwner({{ $u->id }})" class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50">
                                        {{ $u->name }} <span class="text-gray-400">— {{ $u->phone }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="border-t pt-4">
                        <label class="block text-sm font-medium mb-1">Modules</label>
                        <p class="text-xs text-gray-400 mb-2">
                            Service is always on — it's the only vertical built so far. The rest are switches for when their verticals ship.
                        </p>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-400">
                                <input type="checkbox" checked disabled class="rounded"> Service
                            </label>
                            @foreach ($toggleable as $slug => $label)
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="editModules.{{ $slug }}" class="rounded"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
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
                <h3 class="text-lg font-bold mb-2">Delete franchise?</h3>

                @if ($deleteBlockedReason)
                    <p class="text-sm text-gray-600 mb-4">{{ $deleteBlockedReason }}</p>
                    <div class="flex justify-end">
                        <button type="button" wire:click="cancelDelete" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Close</button>
                    </div>
                @else
                    <p class="text-sm text-gray-600 mb-4">This can't be undone.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelDelete" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Cancel</button>
                        <button type="button" wire:click="deleteFranchise" class="bg-red-600 text-white px-6 py-2 rounded text-sm font-medium hover:bg-red-700">Delete</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
