<div>
    <h1 class="text-2xl font-bold mb-4">Bookings</h1>

    @if ($newBookingFlash)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm flex items-center justify-between">
            <span>Booking <span class="font-mono font-medium">{{ $newBookingFlash['code'] }}</span> created — dispatch has started.</span>
            <a href="{{ route('admin.bookings.show', $newBookingFlash['id']) }}" class="text-green-800 font-medium hover:underline">View booking →</a>
        </div>
    @endif

    {{-- New Booking — pinned at the top, same one-screen pattern as
         Categories/Subcategories/Services (Add New panel, list live below,
         no modal). Services vertical only. Drives the real
         app/Actions/CreateBookingAction.php pipeline (order code, pricing,
         ServiceMatchingJob dispatch) — not a separate booking implementation. --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold mb-3">New Booking</h2>

        {{-- Customer --}}
        <div class="mb-3">
            <label class="block text-xs font-medium mb-1">Customer <span class="text-red-500">*</span></label>

            @if ($this->selectedCustomer)
                <div class="flex items-center justify-between bg-gray-50 border rounded px-3 py-2 text-sm max-w-md">
                    <span>{{ $this->selectedCustomer->name }} — {{ $this->selectedCustomer->phone }}</span>
                    <button type="button" wire:click="clearSelectedCustomer" class="text-xs text-blue-600 hover:underline">Change</button>
                </div>
            @else
                <div class="flex flex-wrap items-start gap-3">
                    <div class="w-72">
                        <input type="text" wire:model.live.debounce.400ms="customerSearch"
                               placeholder="Search by phone or name…"
                               class="w-full border rounded px-3 py-2 text-sm">

                        @if ($this->matchingCustomers->isNotEmpty())
                            <div class="border rounded mt-1 divide-y bg-white">
                                @foreach ($this->matchingCustomers as $c)
                                    <button type="button" wire:key="customer-match-{{ $c->id }}" wire:click="selectCustomer({{ $c->id }})"
                                            class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50">
                                        {{ $c->name }} — {{ $c->phone }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <button type="button" wire:click="toggleNewCustomerForm" class="text-xs text-blue-600 hover:underline h-[38px] flex items-center">
                        {{ $showNewCustomerForm ? 'Cancel' : '+ Create new customer' }}
                    </button>

                    @if ($showNewCustomerForm)
                        <div class="flex flex-wrap items-end gap-3 bg-gray-50 border rounded p-3">
                            <div>
                                <input type="text" wire:model="newCustomerName" placeholder="Full name"
                                       class="w-40 border rounded px-3 py-2 text-sm">
                                @error('newCustomerName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <input type="text" wire:model="newCustomerPhone" placeholder="Phone"
                                       class="w-32 border rounded px-3 py-2 text-sm">
                                @error('newCustomerPhone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="createCustomer"
                                    class="bg-slate-900 text-white px-4 h-[38px] rounded text-xs font-medium hover:bg-slate-800">
                                Create &amp; select
                            </button>
                        </div>
                    @endif
                </div>
            @endif
            @error('selectedCustomerId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        {{-- Zone, Service, Price --}}
        <div class="flex flex-wrap items-end gap-3 mb-3">
            <div class="w-56">
                <label class="block text-xs font-medium mb-1">Zone <span class="text-red-500">*</span></label>
                <select wire:model.live="selectedZoneId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Select a zone…</option>
                    @foreach ($zones as $z)
                        <option value="{{ $z->id }}">{{ $z->display_name }} — {{ $z->franchise?->name }}</option>
                    @endforeach
                </select>
                @error('selectedZoneId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="w-56">
                <label class="block text-xs font-medium mb-1">Service <span class="text-red-500">*</span></label>
                <select wire:model.live="selectedServiceId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Select a service…</option>
                    @foreach ($services as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->price_type_label }})</option>
                    @endforeach
                </select>
                @error('selectedServiceId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="w-32">
                <label class="block text-xs font-medium mb-1">Price ({{ $currencySymbol }}) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" wire:model="priceQuoted" class="w-full border rounded px-3 py-2 text-sm">
                @error('priceQuoted') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        @if ($this->selectedZone)
            <p class="text-xs text-gray-400 -mt-2 mb-3">Franchise: {{ $this->selectedZone->franchise?->display_name }}</p>
        @endif

        {{-- Address --}}
        @if ($this->selectedCustomer)
            <div class="mb-3">
                <label class="block text-xs font-medium mb-1">Address <span class="text-red-500">*</span></label>

                @if ($this->selectedCustomer->addresses->isNotEmpty())
                    <div class="flex flex-wrap gap-2 mb-2">
                        @foreach ($this->selectedCustomer->addresses as $a)
                            <label wire:key="address-{{ $a->id }}" class="flex items-start gap-2 border rounded px-3 py-2 text-sm cursor-pointer max-w-xs {{ $selectedAddressId === $a->id ? 'border-slate-900 bg-gray-50' : '' }}">
                                <input type="radio" name="selected-address" wire:click="selectAddress({{ $a->id }})" @checked($selectedAddressId === $a->id) class="mt-0.5">
                                <span>{{ $a->label }} — {{ $a->address_line }}{{ $a->city ? ', '.$a->city : '' }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <button type="button" wire:click="toggleNewAddressForm" class="text-xs text-blue-600 hover:underline">
                    {{ $showNewAddressForm ? 'Cancel' : '+ Add new address' }}
                </button>

                @if ($showNewAddressForm)
                    <div class="bg-gray-50 border rounded p-3 mt-2 space-y-3 max-w-2xl">
                        <div class="flex flex-wrap gap-3">
                            <input type="text" wire:model="newAddressLabel" placeholder="Label (Home/Work/Other)"
                                   class="w-40 border rounded px-3 py-2 text-sm">
                            <input type="text" wire:model="newAddressPincode" placeholder="Pincode"
                                   class="w-32 border rounded px-3 py-2 text-sm">
                            <input type="text" wire:model="newAddressCity" placeholder="City"
                                   class="w-40 border rounded px-3 py-2 text-sm">
                        </div>
                        <input type="text" wire:model="newAddressLine" placeholder="Address line"
                               class="w-full border rounded px-3 py-2 text-sm">
                        @error('newAddressLine') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        <input type="text" wire:model="newAddressLandmark" placeholder="Landmark (optional)"
                               class="w-full border rounded px-3 py-2 text-sm">

                        @if ($mapsConfigured)
                            <p class="text-xs text-gray-400">Click the map to place the customer's location.</p>
                            <x-address-map :component-id="$this->getId()"
                                            lat-model="newAddressLat" lng-model="newAddressLng"
                                            :center-lat="$this->selectedZone?->center_lat" :center-lng="$this->selectedZone?->center_lng"
                                            height="240px" />
                        @else
                            <div class="flex gap-3">
                                <input type="number" step="any" wire:model="newAddressLat" placeholder="Latitude"
                                       class="w-40 border rounded px-3 py-2 text-sm">
                                <input type="number" step="any" wire:model="newAddressLng" placeholder="Longitude"
                                       class="w-40 border rounded px-3 py-2 text-sm">
                            </div>
                        @endif
                        @error('newAddressLat') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        @error('newAddressLng') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror

                        <div class="flex justify-end">
                            <button type="button" wire:click="addNewAddress"
                                    class="bg-slate-900 text-white px-4 h-[38px] rounded text-xs font-medium hover:bg-slate-800">
                                Save address
                            </button>
                        </div>
                    </div>
                @endif
                @error('selectedAddressId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        @endif

        {{-- Scheduling, payment, note --}}
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium mb-1">Scheduling</label>
                <div class="flex gap-3 text-sm h-[38px] items-center">
                    <label class="flex items-center gap-1"><input type="radio" wire:model.live="bookingType" value="instant"> Instant</label>
                    <label class="flex items-center gap-1"><input type="radio" wire:model.live="bookingType" value="scheduled"> Scheduled</label>
                </div>
            </div>

            @if ($bookingType === 'scheduled')
                <div class="w-48">
                    <label class="block text-xs font-medium mb-1">Scheduled at</label>
                    <input type="datetime-local" wire:model="scheduledAt" class="w-full border rounded px-3 py-2 text-sm">
                    @error('scheduledAt') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="w-40">
                <label class="block text-xs font-medium mb-1">Payment method</label>
                <select wire:model="paymentMethod" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="online">Online</option>
                    <option value="cash">Cash</option>
                    <option value="wallet">Wallet</option>
                </select>
                @error('paymentMethod') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium mb-1">Customer note (optional)</label>
                <input type="text" wire:model="customerNote" class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <button type="button" wire:click="clearNewBookingForm" class="px-4 h-[38px] border border-gray-300 rounded text-sm hover:bg-gray-50">
                Clear
            </button>
            <button type="button" wire:click="createBooking" class="bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800 ml-auto">
                + Create Booking
            </button>
        </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
        <button wire:click="$set('statusFilter', '')"
                class="px-3 py-1.5 rounded text-sm {{ $statusFilter === '' ? 'bg-slate-900 text-white' : 'bg-white border' }}">
            All
        </button>
        @foreach (['pending','searching_provider','assigned','provider_en_route','in_progress','on_hold','completed','cancelled','disputed'] as $status)
            <button wire:click="$set('statusFilter', '{{ $status }}')"
                    class="px-3 py-1.5 rounded text-sm {{ $statusFilter === $status ? 'bg-slate-900 text-white' : 'bg-white border' }}">
                {{ str_replace('_', ' ', $status) }}
                @if(isset($statusCounts[$status]))
                    <span class="opacity-60">({{ $statusCounts[$status] }})</span>
                @endif
            </button>
        @endforeach
    </div>

    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by booking code..."
           class="w-full max-w-sm border rounded px-3 py-2 text-sm mb-4">

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Service</th>
                    <th class="px-4 py-2">Provider</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Created</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bookings as $booking)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs">{{ $booking->code }}</td>
                        <td class="px-4 py-2">{{ $booking->customer->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $booking->service->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $booking->provider?->user?->name ?? '— unassigned —' }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-1 rounded text-xs font-medium',
                                'bg-gray-100 text-gray-700' => $booking->status === 'pending',
                                'bg-blue-100 text-blue-700' => in_array($booking->status, ['searching_provider','assigned','provider_en_route']),
                                'bg-amber-100 text-amber-700' => in_array($booking->status, ['in_progress','on_hold']),
                                'bg-green-100 text-green-700' => $booking->status === 'completed',
                                'bg-red-100 text-red-700' => in_array($booking->status, ['cancelled','disputed']),
                            ])>
                                {{ str_replace('_', ' ', $booking->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ $currencySymbol }}{{ number_format($booking->price_final ?? $booking->price_quoted, 2) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $booking->created_at->diffForHumans() }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.bookings.show', $booking->id) }}" class="text-blue-600 hover:underline">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No bookings match this filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $bookings->links() }}
    </div>

</div>
