<div>
    @if (session('message'))
        <div class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700" data-flash>{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded bg-red-50 px-4 py-2 text-sm text-red-700" data-flash>{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="mb-1 text-2xl font-bold">Function halls</h1>
            <p class="text-sm text-gray-500">Every hall, and every change to a live hall's name, address, photos or description, is checked by the team before customers see it.</p>
        </div>
        <a href="{{ route('admin.halls.settings') }}" class="text-sm underline">Rental Settings →</a>
    </div>

    @if (isset($hall))
        <?php $fmt = fn ($v) => $v === null ? '—' : (is_array($v) ? count($v).' photos' : (string) $v); ?>
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to halls</x-ui.button>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold">{{ $hall->name }} @if ($hall->is_featured) <span class="text-sm text-amber-600">★</span> @endif</h2>
                <p class="text-sm text-gray-500">{{ $hall->hall_type }} · {{ $hall->seating_capacity }} seats @if ($hall->floating_capacity) · {{ $hall->floating_capacity }} standing @endif · {{ $hall->is_ac ? 'AC' : 'Non-AC' }}</p>
                <p class="text-sm text-gray-500">{{ $hall->address_line }} · {{ $hall->franchise?->name }}</p>
                <p class="text-sm text-gray-500">Owner: {{ $hall->provider?->user?->name }} · {{ $hall->contact_phone ?? $hall->provider?->user?->phone }} · documents <x-ui.badge>{{ str_replace('_', ' ', (string) $hall->provider?->kyc_status) }}</x-ui.badge></p>
            </div>
            <div class="flex flex-col items-end gap-1">
                <x-ui.badge size="lg">{{ $hall->statusLabel() }}</x-ui.badge>
                @if ($hall->status === 'approved') <span class="text-xs {{ $hall->is_active ? 'text-green-700' : 'text-gray-500' }}">{{ $hall->is_active ? 'Taking bookings' : 'Switched off' }}</span> @endif
            </div>
        </div>

        @if ($hall->pending_changes)
            <x-ui.card class="mb-4 border-amber-300">
                <h3 class="mb-2 font-semibold text-amber-800">Change waiting (sent {{ $hall->pending_requested_at?->diffForHumans() }})</h3>
                <table class="w-full text-sm" data-testid="hall-pending-changes">
                    <thead class="text-left text-gray-500"><tr><th class="py-1">What</th><th class="py-1">Now</th><th class="py-1">New</th></tr></thead>
                    <tbody>
                        @foreach ($hall->pending_changes as $field => $new)
                            <tr class="border-t align-top">
                                <td class="py-1 pr-3">{{ ['name' => 'Name', 'about' => 'Description', 'address_line' => 'Address', 'lat' => 'Map latitude', 'lng' => 'Map longitude', 'cover_image' => 'Cover photo', 'photos' => 'Photos'][$field] ?? $field }}</td>
                                <td class="py-1 pr-3 text-gray-500">
                                    @if ($field === 'cover_image' && $hall->coverUrl()) <img src="{{ $hall->coverUrl() }}" alt="" class="h-16 rounded"> @else {{ \Illuminate\Support\Str::limit($fmt($hall->getAttribute($field)), 160) }} @endif
                                </td>
                                <td class="py-1">
                                    @if ($field === 'cover_image') <img src="{{ \App\Models\Hall::imageUrl($new) }}" alt="" class="h-16 rounded">
                                    @elseif ($field === 'photos')
                                        <div class="flex flex-wrap gap-1">@foreach ((array) $new as $p) <img src="{{ \App\Models\Hall::imageUrl($p) }}" alt="" class="h-12 w-12 rounded object-cover"> @endforeach</div>
                                    @else {{ \Illuminate\Support\Str::limit($fmt($new), 160) }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.button wire:click="approveChanges({{ $hall->id }})">Approve change</x-ui.button>
                    <x-ui.button variant="danger" wire:click="rejectChanges({{ $hall->id }})">Not accepted</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        @if ($hall->coverUrl())
            <img src="{{ $hall->coverUrl() }}" alt="Hall cover" class="mb-2 aspect-video w-full max-w-xl rounded-lg object-cover">
        @endif
        @if ($hall->photoUrls())
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach ($hall->photoUrls() as $url) <img src="{{ $url }}" alt="" class="h-20 w-28 rounded object-cover" loading="lazy"> @endforeach
            </div>
        @endif

        <x-ui.card class="mb-4">
            <h3 class="mb-2 font-semibold">Slots and prices</h3>
            @foreach ($hall->slots as $s)
                <div @class(['flex justify-between border-t py-2 text-sm first:border-t-0', 'text-gray-400 line-through' => ! $s->is_active])>
                    <span>{{ $s->name }} <span class="text-gray-500">· {{ $s->timeLabel() }}</span></span>
                    <span>₹{{ number_format((float) $s->price) }}</span>
                </div>
            @endforeach
            <p class="mt-2 text-sm text-gray-500">Deposit: {{ $hall->deposit_amount ? '₹'.number_format((float) $hall->deposit_amount) : 'none' }} · Parking: {{ $hall->parking_cars ?? '—' }} cars · Rooms: {{ $hall->rooms ?? '—' }} @if ($hall->gst_number) · GST {{ $hall->gst_number }} @endif</p>
        </x-ui.card>

        @if ($hall->amenities)
            <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">Facilities</h3><p class="text-sm text-gray-700">{{ implode(' · ', $hall->amenities) }}</p></x-ui.card>
        @endif
        @if ($hall->about)
            <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">About</h3><p class="whitespace-pre-line text-sm text-gray-700">{{ $hall->about }}</p></x-ui.card>
        @endif
        @if ($hall->rules)
            <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">Rules</h3><p class="whitespace-pre-line text-sm text-gray-700">{{ $hall->rules }}</p></x-ui.card>
        @endif

        <div class="mb-3">
            <input type="text" wire:model="reason" maxlength="500" placeholder="Reason, when you send it back or do not accept a change (the owner sees it)" class="w-full rounded border px-3 py-2 text-sm">
            @error('reason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($hall->status === 'in_review')
                <x-ui.button wire:click="approve({{ $hall->id }})">Approve</x-ui.button>
            @endif
            @if (in_array($hall->status, ['in_review', 'approved'], true))
                <x-ui.button variant="danger" wire:click="sendBack({{ $hall->id }})" wire:confirm="Send this hall back to the owner? It stops taking bookings until approved again.">Send back for changes</x-ui.button>
            @endif
            @if ($hall->status === 'approved')
                <x-ui.button variant="secondary" wire:click="toggleActive({{ $hall->id }})">{{ $hall->is_active ? 'Switch off bookings' : 'Switch on bookings' }}</x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="toggleFeatured({{ $hall->id }})">{{ $hall->is_featured ? 'Remove from featured' : 'Feature it' }}</x-ui.button>
        </div>
    @else
        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All'] + \App\Models\Hall::STATUS_LABELS + ['changes' => 'Changes waiting'.($changesWaiting ? ' ('.$changesWaiting.')' : '')]" :active="$statusFilter" model="statusFilter" />
        <x-ui.table>
            <x-slot:footer>{{ $halls->links() }}</x-slot:footer>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <x-ui.sno-th />
                    <th class="px-4 py-2">Hall</th>
                    <th class="px-4 py-2">Owner</th>
                    <th class="px-4 py-2">Seats</th>
                    <th class="px-4 py-2">From</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($halls as $h)
                    <tr class="border-t" data-testid="hall-row">
                        <x-ui.sno :rows="$halls" :loop="$loop" />
                        <td class="px-4 py-2"><div class="font-medium">{{ $h->name }} @if ($h->is_featured) <span class="text-xs text-amber-600">★</span> @endif</div><div class="text-xs text-gray-500">{{ $h->hall_type }} · {{ $h->franchise?->name }}</div></td>
                        <td class="px-4 py-2">{{ $h->provider?->user?->name }}<div class="text-xs text-gray-500">{{ $h->contact_phone }}</div></td>
                        <td class="px-4 py-2">{{ $h->seating_capacity ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $h->fromPrice() !== null ? '₹'.number_format($h->fromPrice()) : '—' }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge>{{ $h->statusLabel() }}</x-ui.badge>
                            @if ($h->pending_changes) <div class="mt-1 text-xs text-amber-700">Change waiting</div> @endif
                            @if ($h->status === 'approved' && ! $h->is_active) <div class="mt-1 text-xs text-gray-500">Switched off</div> @endif
                        </td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewHall({{ $h->id }})">View</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">No halls here.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif
</div>
