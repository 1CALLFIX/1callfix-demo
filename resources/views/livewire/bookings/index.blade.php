<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Bookings</h1>
        <button type="button" wire:click="openNewBookingModal"
                class="bg-slate-900 text-white px-4 py-2 rounded text-sm font-medium hover:bg-slate-800">
            + New Booking
        </button>
    </div>

    @if ($newBookingFlash)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm flex items-center justify-between">
            <span>Booking <span class="font-mono font-medium">{{ $newBookingFlash['code'] }}</span> created — dispatch has started.</span>
            <a href="{{ route('admin.bookings.show', $newBookingFlash['id']) }}" class="text-green-800 font-medium hover:underline">View booking →</a>
        </div>
    @endif

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
                        <td class="px-4 py-2">₹{{ number_format($booking->price_final ?? $booking->price_quoted, 2) }}</td>
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

    {{-- New Booking modal — Services vertical only. Drives the real
         app/Actions/CreateBookingAction.php pipeline (order code, pricing,
         ServiceMatchingJob dispatch), not a separate booking implementation. --}}
    @if ($showNewBookingModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4 overflow-y-auto" wire:click.self="closeNewBookingModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-6 my-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">New Booking</h3>
                    <button type="button" wire:click="closeNewBookingModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="space-y-6">
                    {{-- Customer --}}
                    <div>
                        <label class="block text-sm font-medium mb-1">Customer <span class="text-red-500">*</span></label>

                        @if ($this->selectedCustomer)
                            <div class="flex items-center justify-between bg-gray-50 border rounded px-3 py-2 text-sm">
                                <span>{{ $this->selectedCustomer->name }} — {{ $this->selectedCustomer->phone }}</span>
                                <button type="button" wire:click="clearSelectedCustomer" class="text-xs text-blue-600 hover:underline">Change</button>
                            </div>
                        @else
                            <input type="text" wire:model.live.debounce.400ms="customerSearch"
                                   placeholder="Search by phone or name…"
                                   class="w-full border rounded px-3 py-2 text-sm">

                            @if ($this->matchingCustomers->isNotEmpty())
                                <div class="border rounded mt-1 divide-y">
                                    @foreach ($this->matchingCustomers as $c)
                                        <button type="button" wire:key="customer-match-{{ $c->id }}" wire:click="selectCustomer({{ $c->id }})"
                                                class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50">
                                            {{ $c->name }} — {{ $c->phone }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            <button type="button" wire:click="toggleNewCustomerForm" class="text-xs text-blue-600 hover:underline mt-1">
                                {{ $showNewCustomerForm ? 'Cancel' : '+ Create new customer' }}
                            </button>

                            @if ($showNewCustomerForm)
                                <div class="grid grid-cols-2 gap-3 mt-2 bg-gray-50 border rounded p-3">
                                    <div>
                                        <input type="text" wire:model="newCustomerName" placeholder="Full name"
                                               class="w-full border rounded px-3 py-2 text-sm">
                                        @error('newCustomerName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <input type="text" wire:model="newCustomerPhone" placeholder="Phone"
                                               class="w-full border rounded px-3 py-2 text-sm">
                                        @error('newCustomerPhone') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="col-span-2 flex justify-end">
                                        <button type="button" wire:click="createCustomer"
                                                class="bg-slate-900 text-white px-4 py-1.5 rounded text-xs font-medium hover:bg-slate-800">
                                            Create &amp; select
                                        </button>
                                    </div>
                                </div>
                            @endif
                        @endif
                        @error('selectedCustomerId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Zone / franchise --}}
                    <div>
                        <label class="block text-sm font-medium mb-1">Zone <span class="text-red-500">*</span></label>
                        <select wire:model.live="selectedZoneId" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">Select a zone…</option>
                            @foreach ($zones as $z)
                                <option value="{{ $z->id }}">{{ $z->display_name }} — {{ $z->franchise?->name }}</option>
                            @endforeach
                        </select>
                        @error('selectedZoneId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        @if ($this->selectedZone)
                            <p class="text-xs text-gray-400 mt-1">Franchise: {{ $this->selectedZone->franchise?->display_name }}</p>
                        @endif
                    </div>

                    {{-- Address --}}
                    @if ($this->selectedCustomer)
                        <div>
                            <label class="block text-sm font-medium mb-1">Address <span class="text-red-500">*</span></label>

                            @if ($this->selectedCustomer->addresses->isNotEmpty())
                                <div class="space-y-1 mb-2">
                                    @foreach ($this->selectedCustomer->addresses as $a)
                                        <label wire:key="address-{{ $a->id }}" class="flex items-start gap-2 border rounded px-3 py-2 text-sm cursor-pointer {{ $selectedAddressId === $a->id ? 'border-slate-900 bg-gray-50' : '' }}">
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
                                <div class="bg-gray-50 border rounded p-3 mt-2 space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <input type="text" wire:model="newAddressLabel" placeholder="Label (Home/Work/Other)"
                                               class="border rounded px-3 py-2 text-sm">
                                        <input type="text" wire:model="newAddressPincode" placeholder="Pincode"
                                               class="border rounded px-3 py-2 text-sm">
                                    </div>
                                    <input type="text" wire:model="newAddressLine" placeholder="Address line"
                                           class="w-full border rounded px-3 py-2 text-sm">
                                    @error('newAddressLine') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    <div class="grid grid-cols-2 gap-3">
                                        <input type="text" wire:model="newAddressLandmark" placeholder="Landmark (optional)"
                                               class="border rounded px-3 py-2 text-sm">
                                        <input type="text" wire:model="newAddressCity" placeholder="City"
                                               class="border rounded px-3 py-2 text-sm">
                                    </div>

                                    @if ($mapsConfigured)
                                        <p class="text-xs text-gray-400">Click the map to place the customer's location.</p>
                                        <x-address-map :component-id="$this->getId()"
                                                        lat-model="newAddressLat" lng-model="newAddressLng"
                                                        :center-lat="$this->selectedZone?->center_lat" :center-lng="$this->selectedZone?->center_lng"
                                                        height="260px" />
                                    @else
                                        <div class="grid grid-cols-2 gap-3">
                                            <input type="number" step="any" wire:model="newAddressLat" placeholder="Latitude"
                                                   class="border rounded px-3 py-2 text-sm">
                                            <input type="number" step="any" wire:model="newAddressLng" placeholder="Longitude"
                                                   class="border rounded px-3 py-2 text-sm">
                                        </div>
                                    @endif
                                    @error('newAddressLat') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    @error('newAddressLng') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror

                                    <div class="flex justify-end">
                                        <button type="button" wire:click="addNewAddress"
                                                class="bg-slate-900 text-white px-4 py-1.5 rounded text-xs font-medium hover:bg-slate-800">
                                            Save address
                                        </button>
                                    </div>
                                </div>
                            @endif
                            @error('selectedAddressId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    {{-- Service & price --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Service <span class="text-red-500">*</span></label>
                            <select wire:model.live="selectedServiceId" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Select a service…</option>
                                @foreach ($services as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->price_type_label }})</option>
                                @endforeach
                            </select>
                            @error('selectedServiceId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Price quoted (₹) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" wire:model="priceQuoted" class="w-full border rounded px-3 py-2 text-sm">
                            @error('priceQuoted') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Scheduling & payment --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Scheduling</label>
                            <div class="flex gap-4 text-sm mb-2">
                                <label class="flex items-center gap-1"><input type="radio" wire:model.live="bookingType" value="instant"> Instant</label>
                                <label class="flex items-center gap-1"><input type="radio" wire:model.live="bookingType" value="scheduled"> Scheduled</label>
                            </div>
                            @if ($bookingType === 'scheduled')
                                <input type="datetime-local" wire:model="scheduledAt" class="w-full border rounded px-3 py-2 text-sm">
                                @error('scheduledAt') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            @endif
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Payment method</label>
                            <select wire:model="paymentMethod" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="online">Online</option>
                                <option value="cash">Cash</option>
                                <option value="wallet">Wallet</option>
                            </select>
                            @error('paymentMethod') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Note --}}
                    <div>
                        <label class="block text-sm font-medium mb-1">Customer note (optional)</label>
                        <textarea wire:model="customerNote" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <button type="button" wire:click="closeNewBookingModal" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Cancel</button>
                    <button type="button" wire:click="createBooking" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Create Booking</button>
                </div>
            </div>
        </div>
    @endif
</div>
