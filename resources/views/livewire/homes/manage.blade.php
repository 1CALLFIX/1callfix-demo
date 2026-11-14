<div>
    @if (session('message'))
        <div class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700" data-flash>{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded bg-red-50 px-4 py-2 text-sm text-red-700" data-flash>{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="mb-1 text-2xl font-bold">Property for rent</h1>
            <p class="text-sm text-gray-500">Monthly rent. Every home, and every change to a live home's photos, description, landmark or address, is checked by the team before customers see it.</p>
        </div>
        <a href="{{ route('admin.halls.settings') }}#set-houses-for-rent-choices" class="text-sm underline">Rental Settings →</a>
    </div>

    @if (isset($home))
        <?php $fmt = fn ($v) => $v === null ? '—' : (is_array($v) ? count($v).' photos' : (string) $v); ?>
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to listings</x-ui.button>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold">{{ $home->title }} @if ($home->is_featured) <span class="text-sm text-amber-600">★</span> @endif</h2>
                <p class="text-sm text-gray-500">{{ $symbol }}{{ number_format((float) $home->rent) }} / month · deposit {{ $home->deposit ? $symbol.number_format((float) $home->deposit) : '—' }} · maintenance {{ $home->maintenance ? $symbol.number_format((float) $home->maintenance) : 'included / none' }}</p>
                <p class="text-sm text-gray-500">{{ $home->furnishing }} · {{ $home->floor ?? '—' }} · {{ $home->facing ?? '—' }} · {{ $home->area ? $home->area.' '.$unit : '—' }} · ready {{ $home->available_from?->format('j M Y') }}</p>
                <p class="text-sm text-gray-500">For: {{ implode(', ', (array) $home->tenants) ?: '—' }}</p>
                <p class="text-sm text-gray-500">{{ $home->zone?->name }} · {{ $home->landmark }} · {{ $home->address_line }} · {{ $home->franchise?->name }}</p>
                <p class="text-sm text-gray-500">Owner: {{ $home->provider?->user?->name }} · {{ $home->contact_phone ?? $home->provider?->user?->phone }} · documents <x-ui.badge>{{ str_replace('_', ' ', (string) $home->provider?->kyc_status) }}</x-ui.badge></p>
            </div>
            <div class="flex flex-col items-end gap-1">
                <x-ui.badge size="lg">{{ $home->statusLabel() }}</x-ui.badge>
                @if ($home->status === 'approved') <span class="text-xs text-gray-600">{{ $home->rented_at ? 'Rented' : ($home->is_active ? 'Customers see it'.($home->expires_at ? ' until '.$home->expires_at->format('j M') : '') : 'Switched off') }}</span> @endif
            </div>
        </div>

        @if ($home->pending_changes)
            <x-ui.card class="mb-4 border-amber-300">
                <h3 class="mb-2 font-semibold text-amber-800">Change waiting (sent {{ $home->pending_requested_at?->diffForHumans() }})</h3>
                <table class="w-full text-sm" data-testid="home-pending-changes">
                    <thead class="text-left text-gray-500"><tr><th class="py-1">What</th><th class="py-1">Now</th><th class="py-1">New</th></tr></thead>
                    <tbody>
                        @foreach ($home->pending_changes as $field => $new)
                            <tr class="border-t align-top">
                                <td class="py-1 pr-3">{{ ['about' => 'Description', 'address_line' => 'Address', 'landmark' => 'Landmark', 'lat' => 'Map latitude', 'lng' => 'Map longitude', 'cover_image' => 'Cover photo', 'photos' => 'Photos'][$field] ?? $field }}</td>
                                <td class="py-1 pr-3 text-gray-500">
                                    @if ($field === 'cover_image' && $home->coverUrl()) <img src="{{ $home->coverUrl() }}" alt="" class="h-16 rounded"> @else {{ \Illuminate\Support\Str::limit($fmt($home->getAttribute($field)), 160) }} @endif
                                </td>
                                <td class="py-1">
                                    @if ($field === 'cover_image') <img src="{{ \App\Models\HouseListing::imageUrl($new) }}" alt="" class="h-16 rounded">
                                    @elseif ($field === 'photos')
                                        <div class="flex flex-wrap gap-1">@foreach ((array) $new as $p) <img src="{{ \App\Models\HouseListing::imageUrl($p) }}" alt="" class="h-12 w-12 rounded object-cover"> @endforeach</div>
                                    @else {{ \Illuminate\Support\Str::limit($fmt($new), 160) }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.button wire:click="approveChanges({{ $home->id }})">Approve change</x-ui.button>
                    <x-ui.button variant="danger" wire:click="rejectChanges({{ $home->id }})">Not accepted</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        @if ($home->coverUrl()) <img src="{{ $home->coverUrl() }}" alt="Property cover" class="mb-2 aspect-video w-full max-w-xl rounded-lg object-cover"> @endif
        @if ($home->photoUrls())
            <div class="mb-4 flex flex-wrap gap-2">@foreach ($home->photoUrls() as $url) <img src="{{ $url }}" alt="" class="h-20 w-28 rounded object-cover" loading="lazy"> @endforeach</div>
        @endif
        @if ($home->amenities) <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">Facilities</h3><p class="text-sm text-gray-700">{{ implode(' · ', $home->amenities) }}</p></x-ui.card> @endif
        @if ($home->rule_list) <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">House rules</h3><p class="text-sm text-gray-700">{{ implode(' · ', $home->rule_list) }}</p></x-ui.card> @endif
        @if ($home->about) <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">About</h3><p class="whitespace-pre-line text-sm text-gray-700">{{ $home->about }}</p></x-ui.card> @endif

        <div class="mb-3">
            <input type="text" wire:model="reason" maxlength="500" placeholder="Reason, when you send it back or do not accept a change (the owner sees it)" class="w-full rounded border px-3 py-2 text-sm">
            @error('reason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($home->status === 'in_review') <x-ui.button wire:click="approve({{ $home->id }})">Approve</x-ui.button> @endif
            @if (in_array($home->status, ['in_review', 'approved'], true))
                <x-ui.button variant="danger" wire:click="sendBack({{ $home->id }})" wire:confirm="Send this home back to the owner? Customers stop seeing it until approved again.">Send back for changes</x-ui.button>
            @endif
            @if ($home->status === 'approved')
                <x-ui.button variant="secondary" wire:click="toggleActive({{ $home->id }})">{{ $home->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="toggleFeatured({{ $home->id }})">{{ $home->is_featured ? 'Remove from featured' : 'Feature it' }}</x-ui.button>
        </div>
    @else
        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All'] + \App\Models\HouseListing::STATUS_LABELS + ['changes' => 'Changes waiting'.($changesWaiting ? ' ('.$changesWaiting.')' : ''), 'visits' => 'Visit requests']" :active="$statusFilter" model="statusFilter" />
        @if ($statusFilter === 'visits')
            <x-ui.table>
                <x-slot:footer>{{ $visits->links() }}</x-slot:footer>
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr><x-ui.sno-th /><th class="px-4 py-2">Visit</th><th class="px-4 py-2">Home</th><th class="px-4 py-2">Customer</th><th class="px-4 py-2">Asked</th><th class="px-4 py-2">Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($visits as $v)
                        <tr class="border-t" data-testid="admin-visit-row">
                            <x-ui.sno :rows="$visits" :loop="$loop" />
                            <td class="px-4 py-2">{{ $v->visit_date->format('j M Y') }}<div class="text-xs text-gray-500">{{ $v->slot }}</div></td>
                            <td class="px-4 py-2">{{ $v->listing?->title }}</td>
                            <td class="px-4 py-2">{{ $v->customer_name }}<div class="text-xs text-gray-500">{{ $v->customer_phone }}</div></td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $v->created_at->diffForHumans() }}</td>
                            <td class="px-4 py-2"><x-ui.badge>{{ $v->statusLabel() }}</x-ui.badge>@if ($v->owner_note) <div class="mt-1 text-xs text-gray-500">{{ $v->owner_note }}</div> @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">No visit requests yet.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        @else
            <x-ui.table>
                <x-slot:footer>{{ $homes->links() }}</x-slot:footer>
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr><x-ui.sno-th /><th class="px-4 py-2">Home</th><th class="px-4 py-2">Owner</th><th class="px-4 py-2">Rent</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody>
                    @forelse ($homes as $h)
                        <tr class="border-t" data-testid="home-row">
                            <x-ui.sno :rows="$homes" :loop="$loop" />
                            <td class="px-4 py-2"><div class="font-medium">{{ $h->title }} @if ($h->is_featured) <span class="text-xs text-amber-600">★</span> @endif</div><div class="text-xs text-gray-500">{{ $h->zone?->name }} · {{ $h->franchise?->name }}</div></td>
                            <td class="px-4 py-2">{{ $h->provider?->user?->name }}<div class="text-xs text-gray-500">{{ $h->contact_phone }}</div></td>
                            <td class="px-4 py-2">{{ $h->rent ? $symbol.number_format((float) $h->rent) : '—' }}</td>
                            <td class="px-4 py-2">
                                <x-ui.badge>{{ $h->statusLabel() }}</x-ui.badge>
                                @if ($h->pending_changes) <div class="mt-1 text-xs text-amber-700">Change waiting</div> @endif
                                @if ($h->rented_at) <div class="mt-1 text-xs text-gray-500">Rented</div> @endif
                            </td>
                            <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="view({{ $h->id }})">View</x-ui.button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        @endif
    @endif
</div>
