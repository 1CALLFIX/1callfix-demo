@php
    $statusBadgeColors = [
        'live' => 'green',
        'scheduled' => 'blue',
        'expired' => 'amber',
        'inactive' => 'gray',
    ];
@endphp

<div>
    <h1 class="text-2xl font-bold mb-4">Banners</h1>

    {{-- Ad-revenue headline numbers, split by slot: the two are sold at
         different rates, so a single combined total would hide which
         inventory is actually earning. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <x-ui.card class="border-l-4 border-indigo-500">
            <p class="text-xs text-gray-500">Top slot — home hero</p>
            <p class="text-2xl font-bold">{{ $currencySymbol }}{{ number_format($revenueByPlacement['top']->total ?? 0, 2) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">
                {{ $revenueByPlacement['top']->slots ?? 0 }} sold · {{ $liveByPlacement['top'] ?? 0 }} live now
            </p>
        </x-ui.card>
        <x-ui.card class="border-l-4 border-teal-500">
            <p class="text-xs text-gray-500">Mid slot — between modules</p>
            <p class="text-2xl font-bold">{{ $currencySymbol }}{{ number_format($revenueByPlacement['mid']->total ?? 0, 2) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">
                {{ $revenueByPlacement['mid']->slots ?? 0 }} sold · {{ $liveByPlacement['mid'] ?? 0 }} live now
            </p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs text-gray-500">Paid slots expiring in 7 days</p>
            <p @class(['text-2xl font-bold', 'text-amber-600' => $expiringSoon > 0])>{{ $expiringSoon }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs text-gray-500">Total ad revenue booked</p>
            <p class="text-2xl font-bold">{{ $currencySymbol }}{{ number_format($paidRevenue, 2) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ $liveCount }} banners showing now</p>
        </x-ui.card>
    </div>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    {{-- Add New — pinned at the top, list updates live below it. --}}
    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">Add New Banner</h2>

        <div class="flex flex-wrap items-end gap-3">
            <div class="w-28">
                <label class="block text-xs font-medium mb-1">Image <span class="text-red-500">*</span></label>
                <label class="relative flex items-center justify-center w-full h-[38px] border border-dashed rounded cursor-pointer hover:bg-gray-50 overflow-hidden bg-gray-50">
                    <input type="file" wire:model="imageFile" accept="image/png,image/jpeg" class="sr-only">
                    @if ($imageFile)
                        <img src="{{ $imageFile->temporaryUrl() }}" alt="" class="h-full w-full object-contain">
                    @else
                        <span class="text-[11px] text-gray-500" wire:loading.remove wire:target="imageFile">PNG / JPEG</span>
                    @endif
                    <span class="text-[11px] text-gray-500" wire:loading wire:target="imageFile">Uploading…</span>
                </label>
            </div>

            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium mb-1">Title <span class="text-red-500">*</span></label>
                <input type="text" wire:model="title" placeholder="e.g. Monsoon AC Service Offer" class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium mb-1">Link</label>
                <input type="text" wire:model="link" placeholder="https://…" class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Slot <span class="text-red-500">*</span></label>
                <select wire:model="placement" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach ($placements as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 mt-3">
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Module</label>
                <select wire:model.live="module" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All modules</option>
                    @foreach ($modules as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Category</label>
                <select wire:model.live="categoryId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All categories</option>
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
                <label class="block text-xs font-medium mb-1">Franchise</label>
                <select wire:model.live="franchiseId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All franchises</option>
                    @foreach ($franchises as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Zone</label>
                <select wire:model="zoneId" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $franchiseId)>
                    <option value="">{{ $franchiseId ? 'Whole franchise' : 'Pick a franchise first' }}</option>
                    @foreach ($this->zones as $z)
                        <option value="{{ $z->id }}">{{ $z->display_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Starts</label>
                <input type="datetime-local" wire:model="startsAt" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Expires</label>
                <input type="datetime-local" wire:model="expiresAt" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Advertiser</label>
                <input type="text" wire:model="advertiserName" placeholder="Leave blank = house" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Advertiser contact</label>
                <input type="text" wire:model="advertiserContact" placeholder="Phone or email" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="w-36">
                <label class="block text-xs font-medium mb-1">Price paid ({{ $currencySymbol }})</label>
                <input type="number" step="0.01" wire:model="pricePaid" class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <label class="inline-flex items-center gap-2 text-sm h-[38px]">
                <input type="checkbox" wire:model="isActive" class="rounded"> Active
            </label>

            <x-ui.button wire:click="save" class="h-[38px] px-6 ml-auto">+ Add</x-ui.button>
        </div>

        <p class="text-xs text-gray-400 mt-2">
            Leave dates blank for an always-on banner. Leave <span class="font-medium">Price paid</span> blank for a house banner (your own promo) — filling it in marks the slot as sold and counts toward ad revenue.
        </p>

        @if ($errors->any())
            <div class="mt-2 space-y-1">
                @foreach ($errors->all() as $error)
                    <p class="text-red-600 text-xs">{{ $error }}</p>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    {{-- List controls --}}
    <div class="flex items-center gap-2 mb-3 flex-wrap">
        <button type="button" wire:click="toggleReorder"
                @class([
                    'px-3 py-2 border rounded text-sm',
                    'bg-slate-900 text-white border-slate-900' => $reorderMode,
                    'border-gray-300 hover:bg-gray-50' => ! $reorderMode,
                ])>Reorder</button>

        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search title or advertiser"
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
            <div class="w-48">
                <label class="block text-xs font-medium mb-1">Franchise</label>
                <select wire:model.live="filterFranchise" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All franchises</option>
                    @foreach ($franchises as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-52">
                <label class="block text-xs font-medium mb-1">Zone</label>
                <select wire:model.live="filterZone" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $filterFranchise)>
                    <option value="">{{ $filterFranchise ? 'All zones' : 'Pick a franchise first' }}</option>
                    @foreach ($this->filterZones as $z)
                        <option value="{{ $z->id }}">{{ $z->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium mb-1">Slot</label>
                <select wire:model.live="filterPlacement" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All slots</option>
                    <option value="top">Top — home hero</option>
                    <option value="mid">Mid — between modules</option>
                </select>
            </div>
            <div class="w-44">
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
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-40">
                <label class="block text-xs font-medium mb-1">Status</label>
                <select wire:model.live="filterStatus" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="live">Live</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="expired">Expired</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="w-40">
                <label class="block text-xs font-medium mb-1">Type</label>
                <select wire:model.live="filterType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="paid">Paid ad slots</option>
                    <option value="house">House banners</option>
                </select>
            </div>
            @if ($filterFranchise !== '' || $filterZone !== '' || $filterCategory !== '' || $filterPlacement !== '' || $filterModule !== '' || $filterStatus !== '' || $filterType !== '' || $search !== '')
                <button type="button" wire:click="$set('filterFranchise', ''); $set('filterZone', ''); $set('filterCategory', ''); $set('filterPlacement', ''); $set('filterModule', ''); $set('filterStatus', ''); $set('filterType', ''); $set('search', '')"
                        class="text-xs text-blue-600 hover:underline pb-2">Clear all</button>
            @endif
        </div>
    @endif

    @if ($reorderMode)
        <div class="bg-indigo-50 border border-indigo-100 text-indigo-800 rounded p-3 mb-3 text-xs">
            Reorder mode: use the ↑ / ↓ arrows to set the order banners appear in the app. Changes save immediately.
            @if ($filterFranchise !== '' || $filterZone !== '' || $filterCategory !== '' || $filterPlacement !== '' || $filterModule !== '' || $filterStatus !== '' || $filterType !== '' || $search !== '')
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
                    <th class="px-4 py-2">Banner</th>
                    <th class="px-4 py-2">Title</th>
                    <th class="px-4 py-2">Slot</th>
                    <th class="px-4 py-2">Targeting</th>
                    <th class="px-4 py-2">Window</th>
                    <th class="px-4 py-2">Advertiser</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($banners as $i => $banner)
                    <tr class="border-t hover:bg-gray-50" wire:key="banner-{{ $banner->id }}">
                        <td class="px-4 py-2 text-gray-400">{{ $banners->firstItem() + $i }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $banner->id }}</td>
                        <td class="px-4 py-2">
                            <span class="w-24 h-10 rounded border flex items-center justify-center overflow-hidden bg-gray-50">
                                @if ($banner->image_url)
                                    <img src="{{ $banner->image_url }}" alt="" class="w-full h-full object-cover">
                                @else
                                    <span class="text-[10px] text-gray-300">—</span>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-2 font-medium">
                            {{ $banner->title }}
                            @if ($banner->link)
                                <a href="{{ $banner->link }}" target="_blank" rel="noopener noreferrer"
                                   class="block text-xs text-blue-600 hover:underline truncate max-w-[14rem]">{{ $banner->link }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-1 rounded text-xs font-medium',
                                'bg-indigo-100 text-indigo-700' => $banner->placement === 'top',
                                'bg-teal-100 text-teal-700' => $banner->placement !== 'top',
                            ])>{{ $banner->placement_short }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500 text-xs">{{ $banner->targeting }}</td>
                        <td class="px-4 py-2 text-gray-500 text-xs">
                            @if ($banner->starts_at || $banner->expires_at)
                                {{-- Time shown, not date-only: a banner window is set to the hour and a 5.5h IST offset silently rolls a date-only display to the wrong day. Format matches the view-details modal. --}}
                                {{ $banner->starts_at ? app(\App\Services\TimezoneResolver::class)->format($banner->starts_at, $banner->franchise, 'd M Y, H:i') : 'Any time' }}
                                &rarr;
                                {{ $banner->expires_at ? app(\App\Services\TimezoneResolver::class)->format($banner->expires_at, $banner->franchise, 'd M Y, H:i') : 'No end' }}
                            @else
                                <span class="text-gray-400">Always on</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">
                            {{ $banner->advertiser_name ?? '—' }}
                            @if ($banner->advertiser_contact)
                                <span class="block text-xs text-gray-400">{{ $banner->advertiser_contact }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($banner->is_paid)
                                {{ $currencySymbol }}{{ number_format($banner->price_paid, 2) }}
                            @else
                                <span class="text-xs text-gray-400">House</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <x-ui.badge :color="$statusBadgeColors[$banner->status] ?? 'gray'" class="capitalize">
                                {{ $banner->status }}
                            </x-ui.badge>
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-1.5 justify-end">
                                @if ($reorderMode)
                                    <button type="button" wire:click="moveUp({{ $banner->id }})"
                                            class="w-8 h-8 rounded flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700" title="Move up">↑</button>
                                    <button type="button" wire:click="moveDown({{ $banner->id }})"
                                            class="w-8 h-8 rounded flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700" title="Move down">↓</button>
                                @endif

                                <button type="button" wire:click="view({{ $banner->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-amber-500 hover:bg-amber-600 text-white" title="View details">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="edit({{ $banner->id }})"
                                        class="w-8 h-8 rounded flex items-center justify-center bg-slate-700 hover:bg-slate-800 text-white" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                    </svg>
                                </button>

                                <button type="button" wire:click="toggleActive({{ $banner->id }})"
                                        wire:loading.attr="disabled" wire:target="toggleActive({{ $banner->id }})"
                                        @class([
                                            'w-8 h-8 rounded flex items-center justify-center text-white',
                                            'bg-red-500 hover:bg-red-600' => $banner->is_active,
                                            'bg-green-500 hover:bg-green-600' => ! $banner->is_active,
                                        ])
                                        title="{{ $banner->is_active ? 'Deactivate' : 'Activate' }}">
                                    @if ($banner->is_active)
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    @endif
                                </button>

                                <button type="button" wire:click="confirmDelete({{ $banner->id }})"
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
                        @if ($search !== '' || $filterFranchise !== '' || $filterZone !== '' || $filterCategory !== '' || $filterPlacement !== '' || $filterModule !== '' || $filterStatus !== '' || $filterType !== '')
                            No banners match your search or filters.
                        @else
                            No banners yet. Add your first one above.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $banners->links() }}
    </div>

    {{-- View details modal --}}
    @if ($showViewModal && $this->viewingBanner)
        @php $b = $this->viewingBanner; @endphp
        <x-ui.modal :show="true" title="Banner Details" onClose="closeViewModal" maxWidth="xl">
                <div class="space-y-4 text-sm">
                    @if ($b->image_url)
                        <img src="{{ $b->image_url }}" alt="" class="w-full rounded border">
                    @endif

                    <div>
                        <p class="text-xs text-gray-500">Title</p>
                        <p class="font-semibold">{{ $b->title }}</p>
                    </div>

                    @if ($b->link)
                        <div>
                            <p class="text-xs text-gray-500">Link</p>
                            <a href="{{ $b->link }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline break-all">{{ $b->link }}</a>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-gray-500">Slot</p>
                            <p>{{ $b->placement_label }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Targeting</p>
                            <p>{{ $b->targeting }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Status</p>
                            <x-ui.badge :color="$statusBadgeColors[$b->status] ?? 'gray'" class="capitalize">{{ $b->status }}</x-ui.badge>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Starts</p>
                            <p>{{ $b->starts_at ? app(\App\Services\TimezoneResolver::class)->format($b->starts_at, $b->franchise, 'd M Y, H:i') : 'Any time' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Expires</p>
                            <p>{{ $b->expires_at ? app(\App\Services\TimezoneResolver::class)->format($b->expires_at, $b->franchise, 'd M Y, H:i') : 'No end date' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Advertiser</p>
                            <p>{{ $b->advertiser_name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Contact</p>
                            <p>{{ $b->advertiser_contact ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Type</p>
                            <p>{{ $b->is_paid ? 'Paid ad slot' : 'House banner' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Price paid</p>
                            <p class="font-medium">{{ $b->is_paid ? $currencySymbol.number_format($b->price_paid, 2) : '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <x-ui.button variant="secondary" wire:click="edit({{ $b->id }})">Edit</x-ui.button>
                    <x-ui.button wire:click="closeViewModal">Close</x-ui.button>
                </div>
        </x-ui.modal>
    @endif

    {{-- Edit modal --}}
    @if ($showEditModal)
        <x-ui.modal :show="true" title="Edit Banner" onClose="closeEditModal" maxWidth="2xl">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Title <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="editTitle" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editTitle') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Image <span class="text-red-500">*</span></label>
                        <div class="flex items-center gap-3">
                            <span class="w-32 h-16 rounded border flex items-center justify-center overflow-hidden shrink-0 bg-gray-50">
                                @if ($editImageFile)
                                    <img src="{{ $editImageFile->temporaryUrl() }}" alt="" class="h-full w-full object-cover">
                                @elseif ($this->editExistingImageUrl)
                                    <img src="{{ $this->editExistingImageUrl }}" alt="" class="h-full w-full object-cover">
                                @else
                                    <span class="text-[11px] text-gray-400">None</span>
                                @endif
                            </span>
                            <label class="px-3 py-1.5 border border-blue-400 text-blue-600 rounded text-xs cursor-pointer hover:bg-blue-50">
                                <input type="file" wire:model="editImageFile" accept="image/png,image/jpeg" class="sr-only">
                                <span wire:loading.remove wire:target="editImageFile">Change</span>
                                <span wire:loading wire:target="editImageFile">Uploading…</span>
                            </label>
                        </div>
                        @error('editImageFile') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Link</label>
                        <input type="text" wire:model="editLink" placeholder="https://…" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editLink') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Slot <span class="text-red-500">*</span></label>
                            <select wire:model="editPlacement" class="w-full border rounded px-3 py-2 text-sm">
                                @foreach ($placements as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('editPlacement') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Module</label>
                            <select wire:model.live="editModule" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">All modules</option>
                                @foreach ($modules as $slug => $label)
                                    <option value="{{ $slug }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('editModule') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Category</label>
                            <select wire:model.live="editCategoryId" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">All categories</option>
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
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Franchise</label>
                            <select wire:model.live="editFranchiseId" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">All franchises</option>
                                @foreach ($franchises as $f)
                                    <option value="{{ $f->id }}">{{ $f->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Zone</label>
                            <select wire:model="editZoneId" class="w-full border rounded px-3 py-2 text-sm" @disabled(! $editFranchiseId)>
                                <option value="">{{ $editFranchiseId ? 'Whole franchise' : 'Pick a franchise first' }}</option>
                                @foreach ($this->editZones as $z)
                                    <option value="{{ $z->id }}">{{ $z->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Starts</label>
                            <input type="datetime-local" wire:model="editStartsAt" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editStartsAt') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Expires</label>
                            <input type="datetime-local" wire:model="editExpiresAt" class="w-full border rounded px-3 py-2 text-sm">
                            @error('editExpiresAt') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Advertiser</label>
                            <input type="text" wire:model="editAdvertiserName" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Contact</label>
                            <input type="text" wire:model="editAdvertiserContact" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Price paid ({{ $currencySymbol }})</label>
                            <input type="number" step="0.01" wire:model="editPricePaid" class="w-full border rounded px-3 py-2 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Blank = house banner.</p>
                            @error('editPricePaid') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
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
    @endif

    {{-- Delete confirmation --}}
    <x-ui.modal :show="(bool) $confirmingDeleteId" title="Delete banner?" onClose="cancelDelete">
                <p class="text-sm text-gray-600 mb-4">This can't be undone — the image file is removed too.</p>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="cancelDelete">Cancel</x-ui.button>
                    <x-ui.button variant="danger" wire:click="deleteBanner">Delete</x-ui.button>
                </div>
    </x-ui.modal>
</div>
