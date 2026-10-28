<div>
    <div class="flex items-center justify-between mb-4 gap-4">
        <h1 class="text-2xl font-bold">Charge Waivers</h1>
        <select wire:model.live="statusFilter" class="text-xs border rounded px-2 py-1">
            <option value="open">Needs action</option>
            <option value="all">All</option>
        </select>
    </div>

    <p class="text-sm text-gray-500 mb-4">
        Waiving a charge the customer owes (a cancellation charge, an admin-cancel fee, or a lowered work-done amount) is a money action.
        Within the requester's own limit it applies at once; above it, or above the second-approval amount, a different user approves it here.
        Limits are set in Refund Controls.
    </p>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.table>
        <x-slot:footer>{{ $rows->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <x-ui.tat-heads created="Requested" completed="Closed" tat="Age / TAT" />
                <th class="px-4 py-2">Booking</th>
                <th class="px-4 py-2">What</th>
                <th class="px-4 py-2">Amount</th>
                <th class="px-4 py-2">Franchise</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t align-top hover:bg-gray-50">
                    @php($open = in_array($row->status, [\App\Models\ChargeWaiver::AWAITING, \App\Models\ChargeWaiver::FAILED], true))
                    @php($closedAt = $open ? null : ($row->approved_at ?? $row->rejected_at ?? $row->updated_at))
                    <td class="px-4 py-2"><x-ui.datetime :at="$row->requested_at" /></td>
                    <td class="px-4 py-2"><x-ui.closed-at :at="$closedAt" :outcome="$row->status" :open="$open" open-label="Open" /></td>
                    <td class="px-4 py-2"><x-ui.tat :from="$row->requested_at" :to="$closedAt" :open="$open" :outcome="$row->status" /></td>
                    <td class="px-4 py-2 font-mono text-xs">
                        @if ($row->booking)<a href="{{ route('admin.bookings.show', $row->booking_id) }}" class="text-blue-700 hover:underline">{{ $row->booking->code }}</a>@endif
                        <div class="font-sans text-gray-500">{{ $row->booking?->customer?->name }}</div>
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-700 max-w-xs">
                        <div class="font-medium">{{ \App\Models\ChargeWaiver::KINDS[$row->kind] ?? $row->kind }}</div>
                        <div>{{ $row->request_reason }}</div>
                        <div class="text-gray-400">requested by {{ $row->requestedBy?->name }}</div>
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">₹{{ number_format((float) $row->amount, 2) }}</td>
                    <td class="px-4 py-2">{{ $row->franchise?->name ?? 'HQ only' }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge color="gray">{{ str_replace('_', ' ', $row->status) }}</x-ui.badge>
                        @if ($row->approvedBy)<div class="text-xs text-gray-400 mt-1">approved by {{ $row->approvedBy->name }}</div>@endif
                        @if ($row->rejection_reason)<div class="text-xs text-gray-500 mt-1">rejected: {{ $row->rejection_reason }}</div>@endif
                        @if ($row->failure_message)<div class="text-xs text-red-600 mt-1">{{ $row->failure_message }}</div>@endif
                        @if ($row->escalation_level > 0 && $row->status === 'awaiting_approval')
                            <div class="text-xs text-red-600">escalated (level {{ $row->escalation_level }})</div>
                        @endif
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">
                        @php($action = $actions[$row->id] ?? null)
                        @if ($action === 'approve')
                            <x-ui.button variant="ghost" wire:click="startAction({{ $row->id }}, 'approve')">Approve</x-ui.button>
                            <x-ui.button variant="ghost" color="red" wire:click="startAction({{ $row->id }}, 'reject')">Reject</x-ui.button>
                        @elseif ($action === 'retry')
                            <x-ui.button variant="ghost" wire:click="retry({{ $row->id }})" wire:confirm="Retry this waiver?">Retry</x-ui.button>
                        @else
                            <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                </tr>
                @if ($actingId === $row->id)
                    <tr class="border-t bg-amber-50">
                        <td colspan="9" class="px-4 py-3">
                            <form wire:submit="submitAction" class="space-y-2">
                                <p class="text-sm text-amber-800">
                                    {{ $actingType === 'approve' ? 'Approving waives exactly ₹'.number_format((float) $row->amount, 2).' for the customer.' : 'Rejecting keeps the charge. Nothing changes for the customer.' }}
                                </p>
                                <input type="text" wire:model="reason" placeholder="Reason (required)" class="w-full rounded border-gray-300 text-sm">
                                @error('reason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                <div class="flex gap-2">
                                    <x-ui.button type="submit">Confirm</x-ui.button>
                                    <x-ui.button type="button" variant="ghost" wire:click="cancelAction">Cancel</x-ui.button>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">Nothing in this queue.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
