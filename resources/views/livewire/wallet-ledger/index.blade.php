<div>
    <h1 class="text-2xl font-bold mb-4">Wallet Ledger</h1>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-xs text-gray-500 mb-1">Total credits (filtered)</div>
            <div class="text-xl font-semibold text-green-700 font-mono">{{ $currencySymbol }}{{ number_format($totalCredit, 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-xs text-gray-500 mb-1">Total debits (filtered)</div>
            <div class="text-xl font-semibold text-red-700 font-mono">{{ $currencySymbol }}{{ number_format($totalDebit, 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-xs text-gray-500 mb-1">Net (filtered)</div>
            <div class="text-xl font-semibold font-mono">{{ $currencySymbol }}{{ number_format($totalCredit - $totalDebit, 2) }}</div>
        </div>
    </div>

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search customer name or phone..." class="border rounded px-3 py-2 text-sm w-64">
        <select wire:model.live="typeFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All types</option>
            <option value="credit">Credit</option>
            <option value="debit">Debit</option>
        </select>
        <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="successful">Successful</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
        </select>
        <input type="date" wire:model.live="fromDate" class="border rounded px-3 py-2 text-sm">
        <span class="self-center text-gray-400 text-sm">to</span>
        <input type="date" wire:model.live="toDate" class="border rounded px-3 py-2 text-sm">
    </div>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Amount</th>
                    <th class="px-4 py-2">Type</th>
                    <th class="px-4 py-2">Reason</th>
                    <th class="px-4 py-2">Ref</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2">{{ $t->wallet->user->name ?? '—' }} <span class="text-gray-400">({{ $t->wallet->user->phone ?? '—' }})</span></td>
                        <td class="px-4 py-2 font-mono @if($t->is_credit) text-green-700 @else text-red-700 @endif">
                            {{ $t->is_credit ? '+' : '−' }}{{ $currencySymbol }}{{ number_format($t->amount, 2) }}
                        </td>
                        <td class="px-4 py-2">
                            <span @class(['px-2 py-0.5 rounded text-xs', 'bg-green-100 text-green-700' => $t->is_credit, 'bg-red-100 text-red-700' => ! $t->is_credit])>
                                {{ $t->is_credit ? 'Credit' : 'Debit' }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $t->reason }}</td>
                        <td class="px-4 py-2 text-gray-400 font-mono text-xs">{{ $t->ref }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-0.5 rounded text-xs',
                                'bg-green-100 text-green-700' => $t->status === 'successful',
                                'bg-amber-100 text-amber-700' => $t->status === 'pending',
                                'bg-red-100 text-red-700' => $t->status === 'failed',
                            ])>{{ ucfirst($t->status) }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $t->created_at->format('d M Y, h:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No wallet transactions match your filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $transactions->links() }}</div>
    </div>
</div>
