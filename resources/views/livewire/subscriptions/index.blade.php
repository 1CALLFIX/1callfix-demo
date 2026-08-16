<div>
    <h1 class="text-2xl font-bold mb-1">Subscriptions</h1>
    <p class="text-sm text-gray-500 mb-4">Every purchased plan instance — customer, provider, or business account. Manual adjustments are always ledger-backed and visible in each entitlement's usage history.</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="pending_payment">Pending payment</option>
            <option value="active">Active</option>
            <option value="grace_period">Grace period</option>
            <option value="past_due">Past due</option>
            <option value="paused">Paused</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
            <option value="failed">Failed</option>
        </select>
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $subscriptions->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Subscriber</th>
                <th class="px-4 py-2">Plan</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Period end</th>
                <th class="px-4 py-2">Balances</th>
                <th class="px-4 py-2 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($subscriptions as $s)
                <tr class="border-t hover:bg-gray-50 align-top" wire:key="sub-{{ $s->id }}">
                    <td class="px-4 py-2">{{ $s->display_label }}</td>
                    <td class="px-4 py-2">{{ $s->plan?->name ?? '—' }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="match(true) {
                            $s->status === 'pending_payment' => 'gray',
                            in_array($s->status, ['active']) => 'green',
                            in_array($s->status, ['grace_period', 'past_due', 'paused']) => 'amber',
                            in_array($s->status, ['expired', 'failed']) => 'red',
                            $s->status === 'cancelled' => 'slate',
                            default => 'gray',
                        }">{{ str_replace('_', ' ', $s->status) }}</x-ui.badge>
                        @if ($s->cancelled_at && $s->status === 'active')
                            <span class="text-xs text-gray-400 block">cancels at period end</span>
                        @endif
                    </td>
                        <td class="px-4 py-2 text-gray-500">{{ $s->current_period_end ? app(\App\Services\TimezoneResolver::class)->format($s->current_period_end, $s->subscribable?->franchise, 'Y-m-d') : '—' }}</td>
                        <td class="px-4 py-2">
                            @forelse ($s->entitlementBalances as $b)
                                <div class="text-xs mb-1">
                                    {{ str_replace('_', ' ', $b->planEntitlement->entitlement_type) }}:
                                    qty {{ $b->remainingQuantity() }} / {{ $currencySymbol }}{{ number_format($b->remainingMonetaryValue(), 2) }}
                                    @if ($adjustingBalanceId === $b->id)
                                        <div class="mt-1 flex gap-1 items-center">
                                            <input type="number" wire:model="adjustQuantityDelta" placeholder="qty Δ" class="w-16 border rounded px-1 py-0.5 text-xs">
                                            <input type="number" step="0.01" wire:model="adjustMonetaryDelta" placeholder="{{ $currencySymbol }} Δ" class="w-16 border rounded px-1 py-0.5 text-xs">
                                            <input type="text" wire:model="adjustReason" placeholder="reason" class="w-24 border rounded px-1 py-0.5 text-xs">
                                            <button type="button" wire:click="confirmAdjust" class="text-green-600 hover:underline">Save</button>
                                        </div>
                                    @else
                                        <button type="button" wire:click="startAdjust({{ $b->id }})" class="text-blue-600 hover:underline ml-1">adjust</button>
                                    @endif
                                </div>
                            @empty
                                <span class="text-xs text-gray-400">—</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if ($s->status === 'active')
                                <x-ui.button variant="ghost" color="gray" class="mr-2" wire:click="pause({{ $s->id }})">Pause</x-ui.button>
                                <x-ui.button variant="ghost" color="red" wire:click="cancel({{ $s->id }})" wire:confirm="Cancel this subscription at period end?">Cancel</x-ui.button>
                            @elseif ($s->status === 'paused')
                                <x-ui.button variant="ghost" color="green" wire:click="resume({{ $s->id }})">Resume</x-ui.button>
                            @elseif (in_array($s->status, ['past_due', 'grace_period', 'expired']))
                                <x-ui.button variant="ghost" wire:click="renewNow({{ $s->id }})">Renew now</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No subscriptions yet.</td></tr>
                @endforelse
            </tbody>
    </x-ui.table>
</div>
