<div>
    <div class="flex items-center justify-between mb-4 gap-4">
        <h1 class="text-2xl font-bold">Pricing Disputes</h1>
        <select wire:model.live="statusFilter" class="text-xs border rounded px-2 py-1">
            <option value="open">Needs action</option>
            <option value="resolved">Resolved</option>
            <option value="all">All</option>
        </select>
    </div>

    <p class="text-sm text-gray-500 mb-4">
        Customers who think they were overcharged raise a dispute from their order page after paying. Talk to both sides, then record the outcome.
        A refund is never automatic: it needs a request and an approval within the limits set in Refund Controls, and is credited to the customer's wallet.
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
                <th class="px-4 py-2">Booking</th>
                <th class="px-4 py-2">Customer's reason</th>
                <th class="px-4 py-2">Paid</th>
                <th class="px-4 py-2">Franchise</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t align-top hover:bg-gray-50">
                    <td class="px-4 py-2 whitespace-nowrap text-gray-500" title="{{ $row->created_at->format('d M Y, h:i A') }}">{{ $row->created_at->diffForHumans(null, true) }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ $row->booking?->code }}<div class="font-sans text-gray-500">{{ $row->customer?->name }}</div></td>
                    <td class="px-4 py-2 text-xs text-gray-700 max-w-xs">{{ $row->reason }}</td>
                    <td class="px-4 py-2 whitespace-nowrap">₹{{ number_format((float) $row->amount_paid, 2) }}</td>
                    <td class="px-4 py-2">{{ $row->franchise?->name ?? 'HQ only' }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge color="gray">{{ $row->status === 'open' ? 'open' : 'resolved: '.str_replace('_', ' ', (string) $row->outcome) }}</x-ui.badge>
                        @if ($row->resolution_note)<div class="text-xs text-gray-500 mt-1">{{ $row->resolution_note }}</div>@endif
                        @if ($row->refund_status)
                            <div class="text-xs mt-1">Refund ₹{{ number_format((float) $row->refund_amount, 2) }} to {{ $row->refund_destination === 'original' ? 'original method' : 'wallet' }}: {{ str_replace('_', ' ', $row->refund_status) }}</div>
                            @if ($row->bearer)<div class="text-xs text-gray-500">{{ \App\Models\BookingDispute::BEARERS[$row->bearer] ?? $row->bearer }} — provider ₹{{ number_format((float) $row->provider_share, 2) }}, company ₹{{ number_format((float) $row->company_share, 2) }}</div>@endif
                            @if ($row->refund_wallet_choice_note)<div class="text-xs text-gray-500">Wallet chosen: {{ $row->refund_wallet_choice_note }}</div>@endif
                            @if ($row->requestedBy)<div class="text-xs text-gray-400">requested by {{ $row->requestedBy->name }}</div>@endif
                            @if ($row->approvedBy)<div class="text-xs text-gray-400">approved by {{ $row->approvedBy->name }}</div>@endif
                            @if ($row->escalation_level > 0 && in_array($row->refund_status, \App\Models\BookingDispute::REFUND_OPEN, true))
                                <div class="text-xs text-red-600">escalated (level {{ $row->escalation_level }})</div>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">
                        @php($action = $actions[$row->id] ?? null)
                        @if ($action === 'resolve')
                            <x-ui.button variant="ghost" wire:click="startAction({{ $row->id }}, 'resolve')">Resolve</x-ui.button>
                        @elseif ($action === 'request')
                            <x-ui.button variant="ghost" wire:click="startAction({{ $row->id }}, 'request')">Request refund</x-ui.button>
                        @elseif ($action === 'approve')
                            <x-ui.button variant="ghost" wire:click="startAction({{ $row->id }}, 'approve')">Approve refund</x-ui.button>
                            @if ($rejectable[$row->id] ?? false)
                                <x-ui.button variant="ghost" color="red" wire:click="startAction({{ $row->id }}, 'reject')">Reject</x-ui.button>
                            @endif
                        @elseif ($action === 'retry')
                            <x-ui.button variant="ghost" wire:click="retry({{ $row->id }})" wire:confirm="Retry this refund?">Retry</x-ui.button>
                        @else
                            <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                </tr>
                @if ($actingId === $row->id)
                    <tr class="border-t bg-amber-50">
                        <td colspan="7" class="px-4 py-3">
                            <form wire:submit="submitAction" class="space-y-2">
                                @if ($actingType === 'resolve')
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <select wire:model.live="outcome" class="rounded border-gray-300 text-sm">
                                            @foreach (\App\Models\BookingDispute::OUTCOMES as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @if ($outcome === 'refund')
                                            <input type="text" inputmode="decimal" wire:model="refundAmount" placeholder="Refund amount (max ₹{{ number_format((float) $row->amount_paid, 2) }})" class="rounded border-gray-300 text-sm">
                                        @endif
                                    </div>
                                    @if ($outcome === 'refund')
                                        @php($kind = app(\App\Services\BookingDisputeService::class)->paymentKind($row->booking))
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <div>
                                                <label class="block text-xs font-medium mb-1">Who bears the refund (required)</label>
                                                <select wire:model.live="bearer" class="w-full rounded border-gray-300 text-sm">
                                                    <option value="">Choose…</option>
                                                    @foreach (\App\Models\BookingDispute::BEARERS as $key => $label)
                                                        <option value="{{ $key }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                @if ($bearer === 'split')
                                                    <input type="text" inputmode="decimal" wire:model="providerShare" placeholder="Provider's share ₹ (company pays the rest)" class="mt-2 w-full rounded border-gray-300 text-sm">
                                                @endif
                                                <p class="text-xs text-gray-500 mt-1">The provider's share is taken from their wallet through the ledger; anything the wallet cannot cover is collected from their next payout request.</p>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium mb-1">Refund goes to (paid {{ $kind }})</label>
                                                <select wire:model.live="destination" class="w-full rounded border-gray-300 text-sm">
                                                    @if ($kind === 'online')<option value="original">Original payment method (default)</option>@endif
                                                    <option value="wallet">Customer's wallet</option>
                                                </select>
                                                @if ($kind === 'online' && $destination === 'wallet')
                                                    <input type="text" wire:model="walletNote" placeholder="Customer agreed to a wallet refund — note (required)" class="mt-2 w-full rounded border-gray-300 text-sm">
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                    <p class="text-xs text-amber-800">Recording a refund here moves no money; it only queues the refund for a request and approval.</p>
                                @elseif ($actingType === 'reject')
                                    <p class="text-sm text-amber-800">Rejecting sends the refund back to "awaiting request". Nothing is refunded.</p>
                                @else
                                    <p class="text-sm text-amber-800">{{ $actingType === 'approve' ? 'Approving a refund of' : 'Requesting a refund of' }} exactly ₹{{ number_format((float) $row->refund_amount, 2) }} to the customer's {{ $row->refund_destination === 'original' ? 'original payment method' : 'wallet' }}.
                                        @if ($actingType === 'request') Within your limit and with no second approval needed it is credited immediately; otherwise a different approver must approve it. @endif</p>
                                @endif
                                <input type="text" wire:model="reason" placeholder="{{ $actingType === 'resolve' ? 'Resolution note (required)' : 'Reason (required)' }}" class="w-full rounded border-gray-300 text-sm">
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
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">Nothing in this queue.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
