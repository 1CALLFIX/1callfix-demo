<div>
    @if (session('message'))
        <div class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700" data-flash>{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded bg-red-50 px-4 py-2 text-sm text-red-700" data-flash>{{ session('error') }}</div>
    @endif

    <h1 class="mb-1 text-2xl font-bold">Events & organisers</h1>
    <p class="mb-4 text-sm text-gray-500">Every organiser and every event is checked by the team before tickets can be sold.</p>

    <div class="mb-4 flex gap-2">
        <button type="button" wire:click="setTab('events')" @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-gray-900 text-white' => $tab === 'events', 'border bg-white text-gray-700' => $tab !== 'events'])>
            Events @if ($inReview) <span class="ml-1 rounded-full bg-amber-500 px-1.5 text-xs text-white">{{ $inReview }}</span> @endif
        </button>
        <button type="button" wire:click="setTab('organisers')" @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-gray-900 text-white' => $tab === 'organisers', 'border bg-white text-gray-700' => $tab !== 'organisers'])>
            Organisers @if ($pendingOrganisers) <span class="ml-1 rounded-full bg-amber-500 px-1.5 text-xs text-white">{{ $pendingOrganisers }}</span> @endif
        </button>
    </div>

    @if ($tab === 'organisers')
        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All', 'pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Not approved']" :active="$approvalFilter" model="approvalFilter" />
        <div class="mb-3">
            <input type="text" wire:model="rejectReason" maxlength="500" placeholder="Reason, when you press Not approved (the organiser sees it)" class="w-full rounded border px-3 py-2 text-sm">
            @error('rejectReason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <x-ui.table>
            <x-slot:footer>{{ $organisers->links() }}</x-slot:footer>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <x-ui.sno-th />
                    <th class="px-4 py-2">Organiser</th>
                    <th class="px-4 py-2">Contact</th>
                    <th class="px-4 py-2">Documents</th>
                    <th class="px-4 py-2">Events</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($organisers as $o)
                    <tr class="border-t" data-testid="organiser-row">
                        <x-ui.sno :rows="$organisers" :loop="$loop" />
                        <td class="px-4 py-2"><div class="font-medium">{{ $o->name }}</div><div class="text-xs text-gray-500">{{ $o->franchise?->name }}</div></td>
                        <td class="px-4 py-2">{{ $o->provider?->user?->name }}<div class="text-xs text-gray-500">{{ $o->contact_phone ?? $o->provider?->user?->phone }}</div></td>
                        <td class="px-4 py-2"><x-ui.badge>{{ str_replace('_', ' ', (string) $o->provider?->kyc_status) }}</x-ui.badge></td>
                        <td class="px-4 py-2">{{ $o->events_count }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge>{{ ['pending' => 'waiting', 'approved' => 'approved', 'rejected' => 'not approved'][$o->approval_status] ?? $o->approval_status }}</x-ui.badge>
                            @if ($o->rejection_reason) <div class="mt-1 text-xs text-red-700">{{ $o->rejection_reason }}</div> @endif
                        </td>
                        <td class="space-x-1 whitespace-nowrap px-4 py-2 text-right">
                            @if ($o->approval_status !== 'approved')
                                <x-ui.button wire:click="approveOrganiser({{ $o->id }})">Approve</x-ui.button>
                            @endif
                            @if ($o->approval_status !== 'rejected')
                                <x-ui.button variant="danger" wire:click="rejectOrganiser({{ $o->id }})">Not approved</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @elseif (isset($event))
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to events</x-ui.button>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold">{{ $event->title }}</h2>
                <p class="text-sm text-gray-500">{{ $event->organiser?->name }} · {{ $event->category }} · {{ $event->min_age ? $event->min_age.'+' : 'All ages' }}</p>
                <p class="text-sm text-gray-500">{{ $event->starts_at->timezone($tz)->format('D, j M Y, g:i A') }} – {{ $event->ends_at->timezone($tz)->format('D, j M Y, g:i A') }}</p>
                <p class="text-sm text-gray-500">{{ $event->venue_name }}, {{ $event->venue_address }}</p>
            </div>
            <x-ui.badge size="lg">{{ $event->statusLabel() }}</x-ui.badge>
        </div>

        @if ($event->coverUrl())
            <img src="{{ $event->coverUrl() }}" alt="Event cover" class="mb-4 aspect-video w-full max-w-xl rounded-lg object-cover">
        @endif

        <x-ui.card class="mb-4">
            <h3 class="mb-2 font-semibold">Sales</h3>
            <p class="text-sm text-gray-600" data-testid="admin-event-sales">
                {{ $sales['orders'] }} paid {{ \Illuminate\Support\Str::plural('order', $sales['orders']) }} · {{ $sales['tickets'] }} tickets · {{ $sales['checked_in'] }} / {{ $sales['people'] }} people in
                · ticket sales ₹{{ number_format($sales['ticket_sales'], 2) }} · paid by buyers ₹{{ number_format($sales['total_paid'], 2) }}
            </p>
            @if ($event->status === 'cancelled')
                <p class="mt-1 text-sm text-red-700">Cancelled {{ $event->cancelled_at?->timezone($tz)->format('j M Y, g:i A') }}: {{ $event->cancellation_reason }}</p>
            @endif
        </x-ui.card>

        <x-ui.card class="mb-4">
            <h3 class="mb-2 font-semibold">Tickets</h3>
            @foreach ($event->ticketTypes as $t)
                <div class="flex justify-between border-t py-2 text-sm first:border-t-0">
                    <span>{{ $t->name }} @if ($t->admits > 1) (admits {{ $t->admits }}) @endif @if ($t->description) <span class="text-gray-500">· {{ $t->description }}</span> @endif</span>
                    <span>₹{{ number_format((float) $t->price) }} × {{ $t->quantity }}</span>
                </div>
            @endforeach
        </x-ui.card>

        @if ($event->description)
            <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">About</h3><p class="whitespace-pre-line text-sm text-gray-700">{{ $event->description }}</p></x-ui.card>
        @endif
        @if ($event->terms)
            <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">Rules</h3><p class="whitespace-pre-line text-sm text-gray-700">{{ $event->terms }}</p></x-ui.card>
        @endif

        <div class="mb-3">
            <input type="text" wire:model="rejectReason" maxlength="500" placeholder="Reason, when you send it back (the organiser sees it)" class="w-full rounded border px-3 py-2 text-sm">
            @error('rejectReason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($event->status === 'in_review')
                <x-ui.button wire:click="approveEvent({{ $event->id }})">Approve</x-ui.button>
            @endif
            @if (in_array($event->status, ['in_review', 'approved'], true))
                <x-ui.button variant="danger" wire:click="rejectEvent({{ $event->id }})">Send back for changes</x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="toggleFeatured({{ $event->id }})">{{ $event->is_featured ? 'Remove from featured' : 'Feature it' }}</x-ui.button>
        </div>
        @if (in_array($event->status, ['in_review', 'approved'], true))
            <x-ui.card class="mt-6">
                <h3 class="font-semibold text-red-700">Cancel the event</h3>
                <p class="mb-2 text-sm text-gray-600">Every buyer is refunded in full (to their wallet or card / UPI) and gets a message with this reason. Cannot be undone.</p>
                <input type="text" wire:model="cancelReason" maxlength="500" placeholder="Reason buyers will see" class="mb-2 w-full rounded border px-3 py-2 text-sm">
                @error('cancelReason') <p class="mb-2 text-sm text-red-700">{{ $message }}</p> @enderror
                <x-ui.button variant="danger" wire:click="cancelEvent({{ $event->id }})" wire:confirm="Cancel this event and refund every buyer in full?">Cancel event and refund everyone</x-ui.button>
            </x-ui.card>
        @endif
    @else
        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All'] + \App\Models\Event::STATUS_LABELS" :active="$statusFilter" model="statusFilter" />
        <x-ui.table>
            <x-slot:footer>{{ $events->links() }}</x-slot:footer>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <x-ui.sno-th />
                    <th class="px-4 py-2">Event</th>
                    <th class="px-4 py-2">Organiser</th>
                    <th class="px-4 py-2">When</th>
                    <th class="px-4 py-2">Tickets</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $e)
                    <tr class="border-t" data-testid="event-row">
                        <x-ui.sno :rows="$events" :loop="$loop" />
                        <td class="px-4 py-2 font-medium">{{ $e->title }} @if ($e->is_featured) <span class="text-xs text-amber-600">★</span> @endif</td>
                        <td class="px-4 py-2">{{ $e->organiser?->name }}</td>
                        <td class="px-4 py-2">{{ $e->starts_at->timezone($tz)->format('j M Y, g:i A') }}</td>
                        <td class="px-4 py-2">{{ (int) $e->tickets_total }}</td>
                        <td class="px-4 py-2"><x-ui.badge>{{ $e->statusLabel() }}</x-ui.badge></td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewEvent({{ $e->id }})">View</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">No events here.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif
</div>
