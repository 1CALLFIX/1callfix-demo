<div>
    @if (session('message'))
        <div class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700" data-flash>{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded bg-red-50 px-4 py-2 text-sm text-red-700" data-flash>{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="mb-1 text-2xl font-bold">Vehicles for rent</h1>
            <p class="text-sm text-gray-500">Owners list their car, bike, auto, van, truck or tractor; customers call the owner. Every vehicle, and every change to a live vehicle's photos, description, landmark or pickup address, is checked by the team before customers see it.</p>
        </div>
        @if (\App\Support\SuperAdminGate::allows(auth()->user())) <a href="{{ route('admin.halls.settings') }}#set-vehicles-for-rent-choices" class="text-sm underline">Rental Settings →</a> @endif
    </div>

    @if (isset($vehicle))
        <?php $fmt = fn ($v) => $v === null ? '—' : (is_array($v) ? count($v).' photos' : (string) $v); ?>
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to vehicles</x-ui.button>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold">{{ $vehicle->title }} @if ($vehicle->is_featured) <span class="text-sm text-amber-600">★</span> @endif</h2>
                <p class="text-sm text-gray-500">{{ $symbol }}{{ number_format((float) $vehicle->price_day) }} / day · per hour {{ $vehicle->price_hour ? $symbol.number_format((float) $vehicle->price_hour) : '—' }} · deposit {{ $vehicle->deposit ? $symbol.number_format((float) $vehicle->deposit) : '—' }} · {{ $vehicle->km_per_day ? $vehicle->km_per_day.' km / day' : 'no km limit' }}</p>
                <p class="text-sm text-gray-500">{{ $vehicle->vehicle_kind }} · {{ trim($vehicle->make.' '.$vehicle->model) ?: '—' }} · {{ $vehicle->year ?? '—' }} · {{ $vehicle->fuel }} · {{ $vehicle->transmission ?? '—' }} · {{ $vehicle->seats ? $vehicle->seats.' seats' : '—' }}</p>
                <p class="text-sm text-gray-500">Rented: {{ implode(', ', (array) $vehicle->driver_options) ?: '—' }}</p>
                <p class="text-sm text-gray-500">{{ $vehicle->zone?->name }} · {{ $vehicle->landmark }} · {{ $vehicle->address_line }} · {{ $vehicle->franchise?->name }}</p>
                <p class="text-sm text-gray-500">Owner: {{ $vehicle->provider?->user?->name }} · {{ $vehicle->contact_phone ?? $vehicle->provider?->user?->phone }} · documents <x-ui.badge>{{ str_replace('_', ' ', (string) $vehicle->provider?->kyc_status) }}</x-ui.badge></p>
            </div>
            <div class="flex flex-col items-end gap-1">
                <x-ui.badge size="lg">{{ $vehicle->statusLabel() }}</x-ui.badge>
                @if ($vehicle->status === 'approved') <span class="text-xs text-gray-600">{{ $vehicle->isLive() ? 'Customers see it'.($vehicle->expires_at ? ' until '.$vehicle->expires_at->format('j M') : '') : ($vehicle->is_active ? 'Waiting for the listing fee or renewal' : 'Switched off') }}</span> @endif
            </div>
        </div>

        @if ($vehicle->pending_changes)
            <x-ui.card class="mb-4 border-amber-300">
                <h3 class="mb-2 font-semibold text-amber-800">Change waiting (sent {{ $vehicle->pending_requested_at?->diffForHumans() }})</h3>
                <table class="w-full text-sm" data-testid="vehicle-pending-changes">
                    <thead class="text-left text-gray-500"><tr><th class="py-1">What</th><th class="py-1">Now</th><th class="py-1">New</th></tr></thead>
                    <tbody>
                        @foreach ($vehicle->pending_changes as $field => $new)
                            <tr class="border-t align-top">
                                <td class="py-1 pr-3">{{ ['about' => 'Description', 'address_line' => 'Pickup address', 'landmark' => 'Landmark', 'lat' => 'Map latitude', 'lng' => 'Map longitude', 'cover_image' => 'Cover photo', 'photos' => 'Photos'][$field] ?? $field }}</td>
                                <td class="py-1 pr-3 text-gray-500">
                                    @if ($field === 'cover_image' && $vehicle->coverUrl()) <img src="{{ $vehicle->coverUrl() }}" alt="" class="h-16 rounded"> @else {{ \Illuminate\Support\Str::limit($fmt($vehicle->getAttribute($field)), 160) }} @endif
                                </td>
                                <td class="py-1">
                                    @if ($field === 'cover_image') <img src="{{ \App\Models\VehicleListing::imageUrl($new) }}" alt="" class="h-16 rounded">
                                    @elseif ($field === 'photos')
                                        <div class="flex flex-wrap gap-1">@foreach ((array) $new as $p) <img src="{{ \App\Models\VehicleListing::imageUrl($p) }}" alt="" class="h-12 w-12 rounded object-cover"> @endforeach</div>
                                    @else {{ \Illuminate\Support\Str::limit($fmt($new), 160) }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.button wire:click="approveChanges({{ $vehicle->id }})">Approve change</x-ui.button>
                    <x-ui.button variant="danger" wire:click="rejectChanges({{ $vehicle->id }})">Not accepted</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        @if ($vehicle->coverUrl()) <img src="{{ $vehicle->coverUrl() }}" alt="Vehicle cover" class="mb-2 aspect-video w-full max-w-xl rounded-lg object-cover"> @endif
        @if ($vehicle->photoUrls())
            <div class="mb-4 flex flex-wrap gap-2">@foreach ($vehicle->photoUrls() as $url) <img src="{{ $url }}" alt="" class="h-20 w-28 rounded object-cover" loading="lazy"> @endforeach</div>
        @endif
        @if ($vehicle->features) <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">Features</h3><p class="text-sm text-gray-700">{{ implode(' · ', $vehicle->features) }}</p></x-ui.card> @endif
        @if ($vehicle->rule_list) <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">Owner's rules</h3><p class="text-sm text-gray-700">{{ implode(' · ', $vehicle->rule_list) }}</p></x-ui.card> @endif
        @if ($vehicle->about) <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">About</h3><p class="whitespace-pre-line text-sm text-gray-700">{{ $vehicle->about }}</p></x-ui.card> @endif

        <div class="mb-3">
            <input type="text" wire:model="reason" maxlength="500" placeholder="Reason, when you send it back or do not accept a change (the owner sees it)" class="w-full rounded border px-3 py-2 text-sm">
            @error('reason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($vehicle->status === 'in_review') <x-ui.button wire:click="approve({{ $vehicle->id }})">Approve</x-ui.button> @endif
            @if (in_array($vehicle->status, ['in_review', 'approved'], true))
                <x-ui.button variant="danger" wire:click="sendBack({{ $vehicle->id }})" wire:confirm="Send this vehicle back to the owner? Customers stop seeing it until approved again.">Send back for changes</x-ui.button>
            @endif
            @if ($vehicle->status === 'approved')
                <x-ui.button variant="secondary" wire:click="toggleActive({{ $vehicle->id }})">{{ $vehicle->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="toggleFeatured({{ $vehicle->id }})">{{ $vehicle->is_featured ? 'Remove from featured' : 'Feature it' }}</x-ui.button>
        </div>
    @else
        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All'] + \App\Models\VehicleListing::STATUS_LABELS + ['changes' => 'Changes waiting'.($changesWaiting ? ' ('.$changesWaiting.')' : '')]" :active="$statusFilter" model="statusFilter" />
        <x-ui.table>
            <x-slot:footer>{{ $vehicles->links() }}</x-slot:footer>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr><x-ui.sno-th /><th class="px-4 py-2">Vehicle</th><th class="px-4 py-2">Owner</th><th class="px-4 py-2">Per day</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @forelse ($vehicles as $v)
                    <tr class="border-t" data-testid="vehicle-row">
                        <x-ui.sno :rows="$vehicles" :loop="$loop" />
                        <td class="px-4 py-2"><div class="font-medium">{{ $v->title }} @if ($v->is_featured) <span class="text-xs text-amber-600">★</span> @endif</div><div class="text-xs text-gray-500">{{ $v->zone?->name }} · {{ $v->franchise?->name }}</div></td>
                        <td class="px-4 py-2">{{ $v->provider?->user?->name }}<div class="text-xs text-gray-500">{{ $v->contact_phone }}</div></td>
                        <td class="px-4 py-2">{{ $v->price_day ? $symbol.number_format((float) $v->price_day) : '—' }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge>{{ $v->statusLabel() }}</x-ui.badge>
                            @if ($v->pending_changes) <div class="mt-1 text-xs text-amber-700">Change waiting</div> @endif
                            @if ($v->status === 'approved' && ! $v->is_active) <div class="mt-1 text-xs text-gray-500">Hidden</div> @endif
                        </td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="view({{ $v->id }})">View</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">Nothing here.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif
</div>
