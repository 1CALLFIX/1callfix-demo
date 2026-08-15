<div>
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold">Payouts</h1>
        <button type="button" wire:click="exportPayouts" class="px-3 py-1.5 border border-gray-300 rounded text-xs hover:bg-gray-50">Export</button>
    </div>
    <p class="text-sm text-gray-500 mb-4">Provider/franchise-owner earnings are credited to their wallet automatically on booking completion. Requesting a payout here holds that amount out of their spendable balance; mark it paid once the transfer is actually made (bank/UPI, outside this system).</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Request a payout --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold mb-3">Request a Payout</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-40">
                <label class="block text-xs font-medium mb-1">Payee type</label>
                <select wire:model.live="payeeType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="provider">Provider</option>
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

            <button type="button" wire:click="request" class="bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800">Request Payout</button>
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
                                @if ($acc->is_verified)
                                    <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700 ml-2">Verified</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-700 ml-2">Unverified</span>
                                @endif
                            </div>
                            @if ($acc->is_verified)
                                <button type="button" wire:click="unverifyPaymentAccount({{ $acc->id }})" class="text-xs text-red-600 hover:underline">Revoke verification</button>
                            @else
                                <button type="button" wire:click="verifyPaymentAccount({{ $acc->id }})" class="text-xs text-green-700 hover:underline">Verify</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Payout list --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
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
                            <span @class([
                                'px-2 py-0.5 rounded text-xs',
                                'bg-gray-100 text-gray-700' => $p->status === 'pending',
                                'bg-amber-100 text-amber-700' => $p->status === 'processing',
                                'bg-green-100 text-green-700' => $p->status === 'paid',
                                'bg-red-100 text-red-700' => $p->status === 'failed',
                            ])>{{ $p->status }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $p->gateway_ref ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $p->created_at->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if ($p->status === 'pending')
                                <button type="button" wire:click="markProcessing({{ $p->id }})" class="text-xs text-blue-600 hover:underline mr-3">Mark Processing</button>
                            @endif
                            @if (in_array($p->status, ['pending', 'processing']))
                                @if ($markingPaidId === $p->id)
                                    <input type="text" wire:model="gatewayRefInput" placeholder="Transfer ref..." class="border rounded px-2 py-1 text-xs w-32 inline-block">
                                    <button type="button" wire:click="confirmMarkPaid" class="text-xs text-green-600 font-medium hover:underline ml-1">Confirm</button>
                                @else
                                    <button type="button" wire:click="startMarkPaid({{ $p->id }})" class="text-xs text-green-600 hover:underline mr-3">Mark Paid</button>
                                @endif
                                <button type="button" wire:click="markFailed({{ $p->id }})" class="text-xs text-red-600 hover:underline">Mark Failed</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No payouts requested yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $payouts->links() }}</div>
    </div>
</div>
