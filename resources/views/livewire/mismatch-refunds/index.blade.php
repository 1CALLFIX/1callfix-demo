<div>
    <div class="flex items-center justify-between mb-4 gap-4">
        <h1 class="text-2xl font-bold">Mismatch Refunds</h1>
        <select wire:model.live="statusFilter" class="text-xs border rounded px-2 py-1">
            <option value="open">Needs action</option>
            <option value="awaiting_request">Awaiting request</option>
            <option value="awaiting_approval">Awaiting approval</option>
            <option value="failed">Failed (retry)</option>
            <option value="refunded">Refunded</option>
            <option value="all">All</option>
        </select>
    </div>

    <p class="text-sm text-gray-500 mb-4">
        Payments where Razorpay captured an amount different from what was expected. Nothing was credited or marked paid.
        A refund returns exactly the captured amount through the company Razorpay account.
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
                <th class="px-4 py-2">Age</th>
                <th class="px-4 py-2">Amount captured</th>
                <th class="px-4 py-2">Franchise</th>
                <th class="px-4 py-2">Razorpay payment</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2 whitespace-nowrap text-gray-500" title="{{ $row->created_at->format('d M Y, h:i A') }}">{{ $row->created_at->diffForHumans(null, true) }}</td>
                    <td class="px-4 py-2 whitespace-nowrap">₹{{ number_format($row->amountRupees(), 2) }}</td>
                    <td class="px-4 py-2">{{ $row->franchise?->name ?? 'HQ only' }}</td>
                    <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $row->gateway_payment_id }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="match($row->status) {
                            'refunded' => 'green',
                            'failed' => 'red',
                            'awaiting_approval' => 'gray',
                            default => 'gray',
                        }">{{ str_replace('_', ' ', $row->status) }}</x-ui.badge>
                        @if ($row->requestedBy)
                            <div class="text-xs text-gray-400 mt-1">requested by {{ $row->requestedBy->name }}</div>
                        @endif
                        @if ($row->approvedBy)
                            <div class="text-xs text-gray-400">approved by {{ $row->approvedBy->name }}</div>
                        @endif
                        @if ($row->escalation_level > 0 && $row->isOpen())
                            <div class="text-xs text-red-600">escalated</div>
                        @endif
                        @if ($row->amount_paise <= 0)
                            <div class="text-xs text-red-600">captured amount missing — handle in Razorpay</div>
                        @endif
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">
                        @php($action = $actions[$row->id] ?? null)
                        @if ($action === 'request')
                            <x-ui.button variant="ghost" wire:click="startAction({{ $row->id }}, 'request')">Request refund</x-ui.button>
                        @elseif ($action === 'approve')
                            <x-ui.button variant="ghost" wire:click="startAction({{ $row->id }}, 'approve')">Approve refund</x-ui.button>
                        @elseif ($action === 'retry')
                            <x-ui.button variant="ghost" wire:click="retry({{ $row->id }})" wire:confirm="Retry this refund?">Retry</x-ui.button>
                        @else
                            <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                </tr>
                @if ($actingId === $row->id)
                    <tr class="border-t bg-amber-50">
                        <td colspan="6" class="px-4 py-3">
                            <form wire:submit="submitAction" class="space-y-2">
                                <p class="text-sm text-amber-800">
                                    {{ $actingType === 'approve' ? 'Approving refunds' : 'Requesting a refund of' }}
                                    exactly ₹{{ number_format($row->amountRupees(), 2) }} to the customer's original payment method.
                                    @if ($actingType === 'request')
                                        If it is within your limit and needs no second approval it is refunded immediately; otherwise it goes to a different approver.
                                    @endif
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
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Nothing in this queue.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
