<div>
    @if ($booking)
        {{-- ============================== Thread view ============================== --}}
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to Conversations</x-ui.button>

        <div class="flex items-center justify-between mt-2 mb-4">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ $booking->code }}</h1>
                <div class="text-sm text-gray-500 mt-1">
                    {{ $booking->customer->name ?? 'Unknown customer' }}
                    @if ($booking->provider?->user) &middot; {{ $booking->provider->user->name }} (Partner) @endif
                    @if ($booking->assignedWorker?->user) &middot; {{ $booking->assignedWorker->user->name }} (Worker) @endif
                </div>
            </div>
            <x-ui.badge size="lg">{{ str_replace('_', ' ', $booking->status) }}</x-ui.badge>
        </div>

        <x-ui.card>
            @forelse ($messages as $message)
                <div class="border-t first:border-t-0 py-3">
                    <div class="flex items-center justify-between text-xs text-gray-400 mb-1">
                        <span class="font-medium text-gray-600">
                            {{ $message->sender->name ?? 'Unknown' }}
                            <span class="text-gray-400">({{ $this->participantRole($booking, $message->sender_id) }})</span>
                            &rarr;
                            {{ $message->receiver->name ?? 'Unknown' }}
                            <span class="text-gray-400">({{ $this->participantRole($booking, $message->receiver_id) }})</span>
                        </span>
                        <span>{{ app(\App\Services\TimezoneResolver::class)->format($message->created_at, $booking->franchise, 'd M Y, h:i:s A') }}</span>
                    </div>
                    @if ($message->message)
                        <p class="text-sm text-gray-800">{{ $message->message }}</p>
                    @endif
                    @if ($message->attachment_url)
                        <a href="{{ route('admin.chat.attachments.show', $message->id) }}" target="_blank" class="text-xs text-blue-600 hover:underline">
                            <x-icon name="document" /> View attachment
                        </a>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-400 py-6 text-center">No messages in this conversation yet.</p>
            @endforelse
        </x-ui.card>
    @else
        {{-- ============================== Conversation list ============================== --}}
        <h1 class="text-2xl font-bold mb-4">Chat</h1>

        <div class="mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search booking code, customer, or partner name..." class="border rounded px-3 py-2 text-sm w-96">
        </div>

        <x-ui.table>
            <x-slot:footer>{{ $bookings->links() }}</x-slot:footer>

            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Booking</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Partner</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Messages</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bookings as $b)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs">{{ $b->code }}</td>
                        <td class="px-4 py-2">{{ $b->customer->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $b->provider->user->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ str_replace('_', ' ', $b->status) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $b->chat_messages_count }}</td>
                        <td class="px-4 py-2 text-right">
                            <x-ui.button variant="ghost" wire:click="selectBooking({{ $b->id }})">View</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No conversations found{{ $search !== '' ? ' matching your search' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif
</div>
