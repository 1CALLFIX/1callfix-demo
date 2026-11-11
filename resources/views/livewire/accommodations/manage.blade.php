<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded" data-flash>{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 text-red-700 text-sm px-4 py-2 rounded" role="alert">{{ session('error') }}</div>
    @endif

    @if ($accommodation)
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to Accommodations</x-ui.button>

        <div class="flex items-center justify-between mt-2 mb-4">
            <div>
                <h1 class="text-2xl font-bold">{{ $accommodation->name }}</h1>
                <div class="text-sm text-gray-500 mt-1">
                    {{ $accommodation->accommodationType?->name }}
                    @if ($accommodation->provider?->user) &middot; Owner: {{ $accommodation->provider->user->name }} @endif
                </div>
            </div>
            @if ($accommodation->is_active) <x-ui.badge color="green" size="lg">Active</x-ui.badge> @else <x-ui.badge color="gray" size="lg">Inactive</x-ui.badge> @endif
        </div>

        <x-ui.card class="mb-6">
            <h2 class="text-lg font-bold mb-3">New Room Type</h2>
            <div class="grid grid-cols-4 gap-4 mb-3">
                <input type="text" wire:model="roomTypeName" placeholder="Room type name" class="border rounded px-3 py-2 text-sm">
                <input type="number" wire:model="roomTypeMaxAdults" placeholder="Max adults" class="border rounded px-3 py-2 text-sm">
                <input type="number" wire:model="roomTypeMaxChildren" placeholder="Max children" class="border rounded px-3 py-2 text-sm">
                <input type="number" wire:model="roomTypeTotalInventory" placeholder="Total rooms" class="border rounded px-3 py-2 text-sm">
            </div>
            <x-ui.button wire:click="createRoomType">Add Room Type</x-ui.button>
        </x-ui.card>

        <x-ui.card class="mb-6">
            <h2 class="text-lg font-bold mb-3">New Rate Plan</h2>
            <div class="grid grid-cols-5 gap-4 mb-3">
                <select wire:model="ratePlanRoomTypeId" class="border rounded px-3 py-2 text-sm">
                    <option value="">Select room type...</option>
                    @foreach ($roomTypes as $rt)
                        <option value="{{ $rt->id }}">{{ $rt->name }}</option>
                    @endforeach
                </select>
                <input type="text" wire:model="ratePlanName" placeholder="Rate plan name" class="border rounded px-3 py-2 text-sm">
                <select wire:model="ratePlanMealPlan" class="border rounded px-3 py-2 text-sm">
                    <option value="room_only">Room only</option>
                    <option value="breakfast">Breakfast included</option>
                    <option value="half_board">Half board</option>
                    <option value="full_board">Full board</option>
                </select>
                <select wire:model="ratePlanCancellationLabel" class="border rounded px-3 py-2 text-sm">
                    <option value="flexible">Flexible</option>
                    <option value="non_refundable">Non-refundable</option>
                </select>
                <input type="number" step="any" wire:model="ratePlanNightlyRate" placeholder="Nightly rate" class="border rounded px-3 py-2 text-sm">
            </div>
            <x-ui.button wire:click="createRatePlan">Add Rate Plan</x-ui.button>
        </x-ui.card>

        <h2 class="text-lg font-bold mb-3">Room Types &amp; Rate Plans</h2>
        @forelse ($roomTypes as $rt)
            <x-ui.card class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <span class="font-semibold">{{ $rt->name }}</span>
                        <span class="text-sm text-gray-500">&middot; {{ $rt->max_adults }} adult(s), {{ $rt->max_children }} child(ren) &middot; {{ $rt->total_inventory }} room(s) total</span>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($rt->is_active) <x-ui.badge color="green">Active</x-ui.badge> @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                        <x-ui.button variant="ghost" wire:click="toggleRoomTypeActive({{ $rt->id }})">{{ $rt->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                    </div>
                </div>

                <table class="w-full text-sm mt-2">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <x-ui.sno-th class="py-1" />
                            <th class="py-1">Rate plan</th>
                            <th class="py-1">Meal plan</th>
                            <th class="py-1">Cancellation</th>
                            <th class="py-1">Nightly rate</th>
                            <th class="py-1">Status</th>
                            <th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rt->ratePlans as $rp)
                            <tr class="border-t">
                                <x-ui.sno :rows="$rt->ratePlans" :loop="$loop" class="py-1" />
                                <td class="py-1">{{ $rp->name }}</td>
                                <td class="py-1">{{ str_replace('_', ' ', $rp->meal_plan) }}</td>
                                <td class="py-1">{{ str_replace('_', ' ', $rp->cancellation_policy_label) }}</td>
                                <td class="py-1">{{ number_format($rp->nightly_rate, 2) }}</td>
                                <td class="py-1">
                                    @if ($rp->is_active) <x-ui.badge color="green">Active</x-ui.badge> @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                                </td>
                                <td class="py-1 text-right"><x-ui.button variant="ghost" wire:click="toggleRatePlanActive({{ $rp->id }})">{{ $rp->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-2 text-gray-400">No rate plans yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.card>
        @empty
            <p class="text-sm text-gray-400">No room types yet -- add one above.</p>
        @endforelse
    @else
        <x-ui.card class="mb-6">
            <h2 class="text-lg font-bold mb-3">New Accommodation</h2>

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
                    <label class="block text-xs font-medium text-gray-600 mb-1">Accommodation Type</label>
                    <select wire:model="accommodationTypeId" class="border rounded px-3 py-2 text-sm w-full">
                        <option value="">Select type...</option>
                        @foreach ($accommodationTypes as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
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
            </div>

            <div class="grid grid-cols-2 gap-4 mb-3">
                <input type="text" wire:model="name" placeholder="Accommodation name" class="border rounded px-3 py-2 text-sm">
                <input type="text" wire:model="addressLine" placeholder="Address" class="border rounded px-3 py-2 text-sm">
            </div>

            <div class="grid grid-cols-2 gap-4 mb-3">
                <input type="number" step="any" wire:model="lat" placeholder="Lat" class="border rounded px-3 py-2 text-sm">
                <input type="number" step="any" wire:model="lng" placeholder="Lng" class="border rounded px-3 py-2 text-sm">
            </div>

            <x-ui.button wire:click="createAccommodation">Create Accommodation</x-ui.button>
        </x-ui.card>

        <h1 class="text-2xl font-bold mb-4">Accommodations</h1>

        <div class="mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name..." class="border rounded px-3 py-2 text-sm w-96">
        </div>

        <x-ui.archive-bars :bars="$archiveBars" />

        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived']"
                          :active="$activeFilter" model="activeFilter" />
        {{-- Hotels H1: hotels that joined through /partners wait here for approval. --}}
        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'Any approval', 'pending' => 'Waiting for approval'.($pendingCount ? ' ('.$pendingCount.')' : ''), 'approved' => 'Approved', 'rejected' => 'Not approved']"
                          :active="$approvalFilter" model="approvalFilter" />
        @if ($approvalFilter === 'pending' || $pendingCount)
            <div class="mb-3 max-w-xl">
                <label for="acc-reject" class="block text-xs font-medium text-gray-600">Reason, used by "Not approved" (the hotel sees it)</label>
                <input id="acc-reject" type="text" wire:model="rejectReason" maxlength="500" class="mt-1 border rounded px-3 py-2 text-sm w-full" placeholder="Photos are blurry; add clear room photos">
                @error('rejectReason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endif

        <x-ui.table>
            <x-slot:footer>{{ $accommodations->links() }}</x-slot:footer>

            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <x-ui.sno-th />
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Type</th>
                    <th class="px-4 py-2">Owner</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Approval</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($accommodations as $a)
                    <tr class="border-t">
                        <x-ui.sno :rows="$accommodations" :loop="$loop" />
                        <td class="px-4 py-2">{{ $a->name }}</td>
                        <td class="px-4 py-2">{{ $a->accommodationType?->name }}</td>
                        <td class="px-4 py-2">{{ $a->provider?->user?->name }}</td>
                        <td class="px-4 py-2">
                            @if ($a->is_active) <x-ui.badge color="green">Active</x-ui.badge> @else <x-ui.badge color="gray">Inactive</x-ui.badge> @endif
                            @if ($a->is_featured) <x-ui.badge color="blue">Featured</x-ui.badge> @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($a->approval_status === 'pending') <x-ui.badge color="amber">Waiting</x-ui.badge>
                            @elseif ($a->approval_status === 'rejected') <x-ui.badge color="red">Not approved</x-ui.badge>
                            @else <x-ui.badge color="green">Approved</x-ui.badge> @endif
                            <span class="block text-xs text-gray-500">{{ $a->photoCount() }} photos</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if (! $a->trashed() && $a->approval_status !== 'approved')
                                <x-ui.button variant="ghost" wire:click="approve({{ $a->id }})" wire:confirm="Approve {{ $a->name }} and show it to customers?">Approve</x-ui.button>
                            @endif
                            @if (! $a->trashed() && $a->approval_status === 'pending')
                                <x-ui.button variant="ghost" wire:click="reject({{ $a->id }})">Not approved</x-ui.button>
                            @endif
                            <x-ui.button variant="ghost" wire:click="viewAccommodation({{ $a->id }})">Rooms &amp; Rates</x-ui.button>
                            @unless ($a->trashed())
                                <x-ui.button variant="ghost" wire:click="editAccommodation({{ $a->id }})">Edit</x-ui.button>
                            @endunless
                            <x-ui.row-actions :id="$a->id" :archived="$a->trashed()" :can-manage="$canManage" :can-force="$canForce" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        <x-ui.modal :show="$showEditModal" title="Edit Accommodation">
            <input type="text" wire:model="editName" class="border rounded px-3 py-2 text-sm w-full mb-3">
            <label class="flex items-center gap-2 text-sm mb-3">
                <input type="checkbox" wire:model="editIsActive"> Active
            </label>
            @error('editIsActive') <p class="mb-3 text-sm text-red-700">{{ $message }}</p> @enderror
            <label class="flex items-center gap-2 text-sm mb-3">
                <input type="checkbox" wire:model="editIsFeatured"> Featured (shown first in its city)
            </label>
            <label class="block text-sm mb-1" for="acc-accept">Bookings</label>
            <select id="acc-accept" wire:model="editNeedsAcceptance" class="border rounded px-3 py-2 text-sm w-full mb-3">
                <option value="">Follow Hotel Settings</option>
                <option value="0">Confirmed at once from the calendar</option>
                <option value="1">Hotel must accept each booking</option>
            </select>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeEditModal">Cancel</x-ui.button>
                <x-ui.button wire:click="saveEdit">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
