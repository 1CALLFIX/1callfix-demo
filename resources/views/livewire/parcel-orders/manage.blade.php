<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    @if ($order)
        {{-- ============================== Detail view ============================== --}}
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to Parcel Orders</x-ui.button>

        <div class="flex items-center justify-between mt-2 mb-4">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ $order->code }}</h1>
                <div class="text-sm text-gray-500 mt-1">
                    {{ $order->customer->name ?? 'Unknown customer' }}
                    @if ($order->assignedWorker?->user) &middot; Rider: {{ $order->assignedWorker->user->name }} @endif
                </div>
            </div>
            <x-ui.badge size="lg">{{ str_replace('_', ' ', $order->status) }}</x-ui.badge>
        </div>

        @error('cancel') <div class="mb-4 bg-red-50 text-red-700 text-sm px-4 py-2 rounded">{{ $message }}</div> @enderror

        <div class="grid grid-cols-2 gap-4 mb-4">
            <x-ui.card>
                <h3 class="font-semibold mb-2">Pickup</h3>
                <p class="text-sm text-gray-600">{{ $order->pickupAddress->address_line ?? '—' }}</p>
                @if ($order->pickup_contact_name || $order->pickup_contact_phone)
                    <p class="text-sm text-gray-500 mt-1">{{ $order->pickup_contact_name }} {{ $order->pickup_contact_phone }}</p>
                @endif
            </x-ui.card>
            <x-ui.card>
                <h3 class="font-semibold mb-2">Dropoff</h3>
                <p class="text-sm text-gray-600">{{ $order->dropoffAddress->address_line ?? '—' }}</p>
                @if ($order->recipient_name || $order->recipient_phone)
                    <p class="text-sm text-gray-500 mt-1">Recipient: {{ $order->recipient_name }} {{ $order->recipient_phone }}</p>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Package</h3>
            <p class="text-sm text-gray-600">{{ $order->package_description ?: '—' }} &middot; {{ ucfirst($order->package_size) }}{{ $order->package_weight_kg ? ' · '.$order->package_weight_kg.' kg' : '' }}{{ $order->distance_km ? ' · '.$order->distance_km.' km' : '' }}</p>
            @if ($order->isScheduled() || $order->package_contents || $order->waiting_charge)
                <p class="text-sm text-gray-600">
                    @if ($order->isScheduled())<span class="font-semibold text-blue-700">Scheduled pickup {{ app(\App\Services\TimezoneResolver::class)->format($order->scheduled_at, $order->franchise, 'D j M, g:i A') }}</span>@endif
                    @if ($order->package_contents) · {{ \App\Services\Parcel\ParcelSettings::CONTENTS[$order->package_contents] ?? '' }} @endif
                    @if ($order->waiting_charge) · waited {{ $order->waiting_minutes }} min, {{ $order->waiting_charge }} paid to the rider @endif
                </p>
            @endif
            @if ($order->customer_note) <p class="text-sm text-gray-500 mt-1">Note: {{ $order->customer_note }}</p> @endif
        </x-ui.card>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Payment</h3>
            <p class="text-sm text-gray-600">
                Quoted: {{ number_format($order->price_quoted, 2) }}
                @if ($order->price_final) &middot; Final: {{ number_format($order->price_final, 2) }} @endif
                &middot; {{ ucfirst($order->payment_status) }} ({{ $order->payment_method ?? '—' }})
                @if ($order->isAwaitingOnlinePayment()) &middot; <span class="text-amber-700">waiting for the customer's online payment — not sent to riders yet</span> @endif
                @if ($order->cash_collected_at) &middot; cash collected {{ $order->cash_collected_at->format('d M, h:i A') }} @endif
            </p>
            @if ($order->price_breakdown)
                <ul class="text-xs text-gray-500 mt-2 space-y-0.5">
                    @foreach ($order->price_breakdown as $line)
                        <li>{{ $line['label'] }}: {{ number_format((float) $line['amount'], 2) }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- Pickup safety (2026-10-29): the rider's refusal and any safety photos. Photos stream from the private disk. --}}
        @if ($order->refused_at || $order->safetyPhotos->isNotEmpty())
            @php($safetyTz = app(\App\Services\TimezoneResolver::class))
            <x-ui.card class="mb-4 border-l-4 border-l-red-500">
                <h3 class="font-semibold mb-2">Pickup safety</h3>
                @if ($order->refused_at)
                    <p class="text-sm text-red-700 font-medium">
                        Refused at pickup by the rider {{ $safetyTz->format($order->refused_at, $order->franchise, 'd M, h:i A') }}:
                        {{ \App\Services\Parcel\ParcelSettings::REFUSAL_REASONS[$order->refusal_reason]['label'] ?? $order->refusal_reason }}
                    </p>
                    @if ($order->refusal_note) <p class="text-sm text-gray-700 mt-1">Rider's note: {{ $order->refusal_note }}</p> @endif
                    <p class="text-xs text-gray-500 mt-1">
                        @if ($order->refused_lat !== null)<a href="https://www.google.com/maps?q={{ $order->refused_lat }},{{ $order->refused_lng }}" target="_blank" rel="noopener" class="underline">Where the rider was</a> · @endif
                        Refusal fee kept: {{ number_format((float) $order->cancellation_fee, 2) }}
                        · Trip pay to the rider: {{ $order->refusal_rider_pay !== null ? number_format((float) $order->refusal_rider_pay, 2) : 'none' }}
                    </p>
                @endif
                @if ($order->safetyPhotos->isNotEmpty())
                    <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach ($order->safetyPhotos as $photo)
                            <figure class="text-xs text-gray-600">
                                <a href="{{ route('admin.parcel-orders.safety-photos.show', $photo->id) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('admin.parcel-orders.safety-photos.show', $photo->id) }}" alt="Safety photo: {{ \App\Services\Parcel\ParcelSettings::SAFETY_PHOTO_STAGES[$photo->stage] ?? $photo->stage }}" class="h-28 w-full rounded object-cover border">
                                </a>
                                <figcaption class="mt-1">
                                    <span class="font-medium">{{ \App\Services\Parcel\ParcelSettings::SAFETY_PHOTO_STAGES[$photo->stage] ?? $photo->stage }}</span>
                                    · {{ $safetyTz->format($photo->taken_at, $order->franchise, 'd M, h:i A') }}
                                    <span class="block">Unusual: {{ collect($photo->concerns)->map(fn ($c) => \App\Services\Parcel\ParcelSettings::SAFETY_CONCERNS[$c] ?? $c)->implode(', ') }}</span>
                                    @if ($photo->note) <span class="block">“{{ $photo->note }}”</span> @endif
                                    @if ($photo->lat !== null) <a href="https://www.google.com/maps?q={{ $photo->lat }},{{ $photo->lng }}" target="_blank" rel="noopener" class="underline">Map</a> @endif
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif

        {{-- Manual assignment: where dispatch hands over when its rounds run out with no acceptance. --}}
        @if ($canAssign)
            <x-ui.card class="mb-4">
                <h3 class="font-semibold mb-2">Assign a rider</h3>
                @if ($riders->isEmpty())
                    <p class="text-sm text-gray-500">No free, active parcel rider in this franchise right now.</p>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <select wire:model="assignWorkerId" class="border rounded px-3 py-2 text-sm">
                            <option value="">Choose a rider</option>
                            @foreach ($riders as $rider)
                                <option value="{{ $rider->id }}">{{ $rider->user?->name }} · {{ $rider->user?->phone }}{{ $rider->is_online ? ' · online' : '' }}</option>
                            @endforeach
                        </select>
                        <x-ui.button wire:click="assignRider">Assign</x-ui.button>
                    </div>
                @endif
                @error('assign') <p class="text-sm text-red-700 mt-2">{{ $message }}</p> @enderror
            </x-ui.card>
        @endif

        {{-- REF 1CF-JOURNEY-001 — the order's journey; the raw log stays below. --}}
        @php($journey = \App\Support\Journey\JourneyBuilder::build('parcel', $order->status, $order->statusHistory))
        @if ($journey)
            <x-ui.card class="mb-4">
                <x-journey.timeline :journey="$journey" variant="compact" />
            </x-ui.card>
        @endif

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Status history</h3>
            @forelse ($order->statusHistory as $h)
                <div class="border-t first:border-t-0 py-2 text-sm">
                    <span class="font-medium">{{ str_replace('_', ' ', $h->status) }}</span>
                    <span class="text-gray-400"> — {{ $h->changed_at?->format('d M Y, h:i:s A') }}</span>
                    @if ($h->note) <div class="text-gray-500">{{ $h->note }}</div> @endif
                </div>
            @empty
                <p class="text-sm text-gray-400">No history yet.</p>
            @endforelse
        </x-ui.card>

        @if (! in_array($order->status, ['delivered', 'cancelled']))
            <x-ui.button variant="danger" wire:click="cancelOrder" wire:confirm="Cancel this parcel order?">Cancel Order</x-ui.button>
        @endif
    @else
        {{-- ============================== Add New panel ============================== --}}
        <x-ui.card class="mb-6">
            <h2 class="text-lg font-bold mb-3">New Parcel Order</h2>
            @error('creation') <div class="mb-3 bg-red-50 text-red-700 text-sm px-4 py-2 rounded">{{ $message }}</div> @enderror

            <div class="grid grid-cols-2 gap-4 mb-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Customer</label>
                    <input type="text" wire:model.live.debounce.300ms="customerSearch" placeholder="Search by name or phone..." class="border rounded px-3 py-2 text-sm w-full">
                    @if ($this->customerResults->isNotEmpty() && ! $selectedCustomerId)
                        <div class="border rounded mt-1 text-sm bg-white shadow">
                            @foreach ($this->customerResults as $c)
                                <div wire:click="selectCustomer({{ $c->id }})" class="px-3 py-1.5 hover:bg-gray-50 cursor-pointer">{{ $c->name }} ({{ $c->phone }})</div>
                            @endforeach
                        </div>
                    @endif
                    @error('selectedCustomerId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Zone</label>
                    <select wire:model="selectedZoneId" class="border rounded px-3 py-2 text-sm w-full">
                        <option value="">Select zone...</option>
                        @foreach ($zones as $z)
                            <option value="{{ $z->id }}">{{ $z->name }}</option>
                        @endforeach
                    </select>
                    @error('selectedZoneId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Pickup address</label>
                    <input type="text" wire:model="pickupAddressLine" placeholder="Address line" class="border rounded px-3 py-2 text-sm w-full mb-1">
                    <div class="flex gap-2">
                        <input type="number" step="any" wire:model="pickupLat" placeholder="Lat" class="border rounded px-3 py-2 text-sm w-1/2">
                        <input type="number" step="any" wire:model="pickupLng" placeholder="Lng" class="border rounded px-3 py-2 text-sm w-1/2">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Dropoff address</label>
                    <input type="text" wire:model="dropoffAddressLine" placeholder="Address line" class="border rounded px-3 py-2 text-sm w-full mb-1">
                    <div class="flex gap-2">
                        <input type="number" step="any" wire:model="dropoffLat" placeholder="Lat" class="border rounded px-3 py-2 text-sm w-1/2">
                        <input type="number" step="any" wire:model="dropoffLng" placeholder="Lng" class="border rounded px-3 py-2 text-sm w-1/2">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-4 mb-3">
                <input type="text" wire:model="packageDescription" placeholder="Package description" class="border rounded px-3 py-2 text-sm col-span-2">
                <input type="number" step="any" wire:model="packageWeightKg" placeholder="Weight (kg)" class="border rounded px-3 py-2 text-sm">
                <select wire:model="packageSize" class="border rounded px-3 py-2 text-sm">
                    <option value="small">Small</option>
                    <option value="medium">Medium</option>
                    <option value="large">Large</option>
                </select>
            </div>

            <label class="mb-3 flex items-start gap-2 text-sm">
                <input type="checkbox" wire:model="restrictedConfirmed" class="mt-0.5 rounded border-gray-300">
                <span>Customer confirmed the parcel has none of: {{ implode('; ', $restrictedItems) }}.</span>
            </label>
            @error('restrictedConfirmed') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="flex items-center gap-3">
                <select wire:model="paymentMethod" class="border rounded px-3 py-2 text-sm">
                    <option value="online">Online</option>
                    <option value="wallet">Wallet</option>
                    <option value="cash">Cash</option>
                </select>
                <x-ui.button wire:click="createOrder">Create Parcel Order</x-ui.button>
            </div>
        </x-ui.card>

        {{-- ============================== List ============================== --}}
        <h1 class="text-2xl font-bold mb-4">Parcel Orders</h1>

        <div class="flex gap-3 mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search order code or customer..." class="border rounded px-3 py-2 text-sm w-96">
            <x-ui.filter-tabs class="w-full" :tabs="['' => 'All', 'flagged' => 'Flagged'] + collect(['pending','searching_worker','assigned','worker_en_route_pickup','picked_up','en_route_dropoff','delivered','cancelled','disputed'])->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()" :active="$statusFilter" model="statusFilter" />
        </div>

        <x-ui.table>
            <x-slot:footer>{{ $orders->links() }}</x-slot:footer>

            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <x-ui.sno-th />
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Rider</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Price</th>
                    <x-ui.tat-heads />
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $o)
                    <tr class="border-t">
                        <x-ui.sno :rows="$orders" :loop="$loop" />
                        <td class="px-4 py-2 font-mono text-xs">{{ $o->code }}</td>
                        <td class="px-4 py-2">{{ $o->customer->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $o->assignedWorker?->user?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge>{{ str_replace('_', ' ', $o->status) }}</x-ui.badge>
                            @if ($o->refused_at) <span class="ml-1 text-xs font-semibold text-red-700">Refused by rider</span>
                            @elseif ($o->safety_flagged_at) <span class="ml-1 text-xs font-semibold text-red-700">Safety photo</span> @endif
                        </td>
                        <td class="px-4 py-2">{{ number_format($o->price_final ?? $o->price_quoted, 2) }}</td>
                        <x-ui.tat-cells :record="$o" done="delivered_at" />
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewOrder({{ $o->id }})">View</x-ui.button></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</div>
