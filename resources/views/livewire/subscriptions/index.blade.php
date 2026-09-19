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
                <th class="px-4 py-2">Activated / period end</th>
                <th class="px-4 py-2">Balances</th>
                <th class="px-4 py-2 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($subscriptions as $s)
                <tr class="border-t hover:bg-gray-50 align-top" wire:key="sub-{{ $s->id }}">
                    <td class="px-4 py-2">
                        {{ $s->display_label }}
                        @if ($s->registeredAddress)
                            <span class="text-xs text-gray-400 block">Registered: {{ $s->registeredAddress->label }} — {{ \Illuminate\Support\Str::limit($s->registeredAddress->address_line, 40) }}</span>
                        @elseif ($s->plan?->isAddressLocked())
                            <span class="text-xs text-amber-600 block">No registered address</span>
                        @endif
                    </td>
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
                        <td class="px-4 py-2 text-gray-500 text-xs">
                            <span class="block">{{ $s->starts_at ? app(\App\Services\TimezoneResolver::class)->format($s->starts_at, $s->subscribable?->franchise, 'Y-m-d') : '—' }} →</span>
                            <span class="block">{{ $s->current_period_end ? app(\App\Services\TimezoneResolver::class)->format($s->current_period_end, $s->subscribable?->franchise, 'Y-m-d') : '—' }}</span>
                        </td>
                        <td class="px-4 py-2">
                            @forelse ($s->entitlementBalances as $b)
                                <div class="text-xs mb-1">
                                    {{ $b->planEntitlement->label ?: str_replace('_', ' ', $b->planEntitlement->entitlement_type) }}:
                                    @if ($b->planEntitlement->quantity !== null)
                                        {{ max(0, $b->remainingQuantity()) }} / {{ $b->granted_quantity + $b->rolled_over_quantity }} left
                                    @elseif ($b->planEntitlement->entitlement_type === 'priority')
                                        included
                                    @else
                                        qty {{ $b->remainingQuantity() }}
                                    @endif
                                    @if ((float) $b->granted_monetary_value + (float) $b->rolled_over_monetary_value > 0)
                                        · {{ $currencySymbol }}{{ number_format($b->remainingMonetaryValue(), 2) }}
                                    @endif
                                    @if ($adjustingBalanceId === $b->id)
                                        <div class="mt-1 flex gap-1 items-center">
                                            <input type="number" wire:model="adjustQuantityDelta" placeholder="qty Δ" class="w-16 border rounded px-1 py-0.5 text-xs">
                                            <input type="number" step="0.01" wire:model="adjustMonetaryDelta" placeholder="{{ $currencySymbol }} Δ" class="w-16 border rounded px-1 py-0.5 text-xs">
                                            <input type="text" wire:model="adjustReason" placeholder="reason" class="w-24 border rounded px-1 py-0.5 text-xs">
                                            <button type="button" wire:click="confirmAdjust" class="text-green-600 hover:underline">Save</button>
                                        </div>
                                    @elseif ($redeemingBalanceId === $b->id)
                                        <div class="mt-1 flex gap-1 items-center">
                                            @if ($b->planEntitlement->requiresCategoryChoice())
                                                <select wire:model="redeemCategory" class="border rounded px-1 py-0.5 text-xs">
                                                    <option value="">choose category…</option>
                                                    @foreach ($b->planEntitlement->redeem_categories as $cat)
                                                        <option value="{{ $cat }}">{{ $cat }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <button type="button" wire:click="confirmRedeem" class="text-green-600 hover:underline">Confirm redeem</button>
                                            <button type="button" wire:click="$set('redeemingBalanceId', null)" class="text-gray-400 hover:underline">cancel</button>
                                        </div>
                                    @else
                                        <button type="button" wire:click="startAdjust({{ $b->id }})" class="text-blue-600 hover:underline ml-1">adjust</button>
                                        @if ($s->status === 'active' && $b->remainingQuantity() > 0)
                                            <button type="button" wire:click="startRedeem({{ $b->id }})" class="text-green-600 hover:underline ml-1">redeem</button>
                                        @endif
                                    @endif
                                </div>
                            @empty
                                <span class="text-xs text-gray-400">—</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <x-ui.button variant="ghost" class="mr-2" wire:click="toggleHistory({{ $s->id }})">{{ $historySubscriptionId === $s->id ? 'Hide history' : 'History' }}</x-ui.button>
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
                    @if ($historySubscriptionId === $s->id)
                        <tr class="border-t bg-gray-50" wire:key="sub-{{ $s->id }}-history">
                            <td colspan="6" class="px-4 py-3">
                                <h3 class="text-xs font-semibold mb-2">Usage & redemption history (latest 60) — every adjustment, redemption and reversal is a ledger row</h3>
                                <table class="w-full text-xs">
                                    <thead class="text-left text-gray-500">
                                        <tr>
                                            <th class="pr-3 py-1">When</th>
                                            <th class="pr-3 py-1">Benefit</th>
                                            <th class="pr-3 py-1">Event</th>
                                            <th class="pr-3 py-1">Qty Δ</th>
                                            <th class="pr-3 py-1">{{ $currencySymbol }} Δ</th>
                                            <th class="pr-3 py-1">Choice</th>
                                            <th class="pr-3 py-1">Booking</th>
                                            <th class="pr-3 py-1">By</th>
                                            <th class="pr-3 py-1">Reason</th>
                                            <th class="pr-3 py-1"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($history['rows'] as $row)
                                            <tr class="border-t" wire:key="ledger-{{ $row->id }}">
                                                <td class="pr-3 py-1 whitespace-nowrap">{{ app(\App\Services\TimezoneResolver::class)->format($row->created_at, $s->subscribable?->franchise, 'Y-m-d H:i') }}</td>
                                                <td class="pr-3 py-1">{{ $row->planEntitlement?->displayName() ?? '—' }}</td>
                                                <td class="pr-3 py-1">{{ $row->event_type }}</td>
                                                <td class="pr-3 py-1">{{ $row->quantity_delta }}</td>
                                                <td class="pr-3 py-1">{{ number_format((float) $row->monetary_delta, 2) }}</td>
                                                <td class="pr-3 py-1">{{ $row->redeemed_category ?? '—' }}</td>
                                                <td class="pr-3 py-1">{{ $row->booking?->code ?? '—' }}</td>
                                                <td class="pr-3 py-1">{{ $row->createdBy?->name ?? 'system' }}</td>
                                                <td class="pr-3 py-1">{{ $row->reason }}</td>
                                                <td class="pr-3 py-1 text-right">
                                                    @if ($row->event_type === 'consume' && ((int) $row->quantity_delta !== 0 || (float) $row->monetary_delta !== 0.0))
                                                        @if (isset($history['reversed'][$row->id]))
                                                            <span class="text-gray-400">reversed</span>
                                                        @else
                                                            <button type="button" wire:click="reverseUsage({{ $row->id }})" wire:confirm="Give this benefit back to the subscriber?" class="text-blue-600 hover:underline">reverse</button>
                                                        @endif
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="10" class="py-2 text-gray-400">No usage recorded yet.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No subscriptions yet.</td></tr>
                @endforelse
            </tbody>
    </x-ui.table>
</div>
