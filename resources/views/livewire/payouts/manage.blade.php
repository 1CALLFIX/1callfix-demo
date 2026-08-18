<div>
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold">Payouts</h1>
        <x-ui.button variant="secondary" size="sm" wire:click="exportPayouts">Export</x-ui.button>
    </div>
    <p class="text-sm text-gray-500 mb-4">Provider/franchise-owner earnings are credited to their wallet automatically on booking completion. Requesting a payout here holds that amount out of their spendable balance; mark it paid once the transfer is actually made (bank/UPI, outside this system).</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Request a payout --}}
    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">Request a Payout</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-40">
                <label class="block text-xs font-medium mb-1">Payee type</label>
                <select wire:model.live="payeeType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="provider">Provider</option>
                    <option value="field_worker">Field Worker</option>
                    <option value="franchise_owner">Franchise Owner</option>
                </select>
            </div>

            <div class="w-64 relative">
                <label class="block text-xs font-medium mb-1">Payee <span class="text-red-500">*</span></label>
                <input type="text" wire:model.live="payeeSearch" placeholder="Search name or phone..." class="w-full border rounded px-3 py-2 text-sm" autocomplete="off">
                @error('selectedPayeeId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                @if ($payeeSearch && $this->matchingPayees->isNotEmpty() && ! $selectedPayeeId)
                    <div class="absolute z-10 bg-white border rounded shadow-sm mt-1 w-full max-h-48 overflow-y-auto">
                        @foreach ($this->matchingPayees as $p)
                            <button type="button" wire:click="selectPayee({{ $p['id'] }}, '{{ addslashes($p['label']) }}')" class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50">
                                {{ $p['label'] }}
                            </button>
                        @endforeach
                    </div>
                @endif
                @if ($selectedPayeeId)
                    <p class="text-xs text-gray-500 mt-1">
                        Selected: {{ $selectedPayeeLabel }} — wallet balance: {{ $currencySymbol }}{{ number_format($this->payeeWalletBalance ?? 0, 2) }}
                    </p>
                @endif
            </div>

            <div class="w-36">
                <label class="block text-xs font-medium mb-1">Amount <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" wire:model="amount" class="w-full border rounded px-3 py-2 text-sm">
                @error('amount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            @if ($selectedPayeeId && $this->payeePaymentAccounts->isNotEmpty())
                <div class="w-56">
                    <label class="block text-xs font-medium mb-1">Payment account</label>
                    <select wire:model="paymentAccountId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">None on file</option>
                        @foreach ($this->payeePaymentAccounts as $acc)
                            <option value="{{ $acc->id }}">{{ strtoupper($acc->account_type) }} — {{ $acc->upi_id ?: $acc->account_number }}{{ $acc->is_verified ? '' : ' (unverified)' }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <x-ui.button class="h-[38px]" wire:click="request">Request Payout</x-ui.button>
        </div>

        @if ($selectedPayeeId && $this->payeePaymentAccounts->isNotEmpty())
            <div class="mt-4 pt-4 border-t">
                <div class="text-xs font-medium text-gray-500 uppercase mb-2">Payee's settlement accounts</div>
                <div class="space-y-1">
                    @foreach ($this->payeePaymentAccounts as $acc)
                        <div class="flex items-center justify-between text-sm border rounded px-3 py-2">
                            <div>
                                {{ strtoupper($acc->account_type) }} — {{ $acc->upi_id ?: $acc->account_number }}
                                @if ($acc->is_default)<span class="text-xs text-gray-400 ml-1">(default)</span>@endif
                                <x-ui.badge class="ml-2" :color="$acc->is_verified ? 'green' : 'amber'">{{ $acc->is_verified ? 'Verified' : 'Unverified' }}</x-ui.badge>
                            </div>
                            @if ($acc->is_verified)
                                <x-ui.button variant="ghost" color="red" wire:click="unverifyPaymentAccount({{ $acc->id }})">Revoke verification</x-ui.button>
                            @else
                                <x-ui.button variant="ghost" color="green" wire:click="verifyPaymentAccount({{ $acc->id }})">Verify</x-ui.button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-ui.card>

    {{-- Payout list --}}
    <x-ui.table>
        <x-slot:footer>{{ $payouts->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Payee</th>
                <th class="px-4 py-2">Amount</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Reference</th>
                <th class="px-4 py-2">Requested</th>
                <th class="px-4 py-2 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payouts as $p)
                <tr class="border-t hover:bg-gray-50" wire:key="payout-{{ $p->id }}">
                    <td class="px-4 py-2">{{ $p->display_label }}</td>
                    <td class="px-4 py-2 font-mono">{{ $currencySymbol }}{{ number_format($p->amount, 2) }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="match($p->status) { 'pending' => 'gray', 'processing' => 'amber', 'paid' => 'green', 'failed' => 'red', default => 'gray' }">{{ $p->status }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $p->gateway_ref ?? '—' }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $p->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        @if ($p->status === 'pending')
                            <x-ui.button variant="ghost" class="mr-3" wire:click="markProcessing({{ $p->id }})">Mark Processing</x-ui.button>
                        @endif
                        @if (in_array($p->status, ['pending', 'processing']))
                            <x-ui.button variant="ghost" color="green" class="mr-3" wire:click="startMarkPaid({{ $p->id }})">Mark Paid</x-ui.button>
                            <x-ui.button variant="ghost" color="red" wire:click="markFailed({{ $p->id }})">Mark Failed</x-ui.button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No payouts requested yet.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>

    {{--
        Phase 21 item TECH-6 (second increment) -- the first real screen
        wiring for x-ui.modal. Previously this was an inline input that
        appeared directly inside the payout's own table row (no cancel
        affordance at all -- once "Mark Paid" was clicked the only way out
        was to actually confirm it). Genuinely modal-shaped: a single
        transfer-reference input gating a confirm action, exactly the
        "confirm dialog with one field" shape the component was built for.
        Same Livewire method names as before (startMarkPaid/confirmMarkPaid/
        gatewayRefInput) -- RowLevelScopeAuthorizationTest's own coverage of
        this flow calls those methods directly and is unaffected. $onClose
        points at the new cancelMarkPaid() method (additive -- it only
        resets the same two properties startMarkPaid() itself resets).
    --}}
    <x-ui.modal :show="$markingPaidId !== null" title="Mark payout paid" onClose="cancelMarkPaid">
        <label class="block text-xs font-medium mb-1">Transfer reference</label>
        <input type="text" wire:model="gatewayRefInput" placeholder="Transfer ref..." class="w-full border rounded px-3 py-2 text-sm">
        @error('gatewayRefInput') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelMarkPaid">Cancel</x-ui.button>
            <x-ui.button variant="success" wire:click="confirmMarkPaid">Confirm</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
