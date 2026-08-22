<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Wallet Ledger</h1>
        <x-ui.button variant="secondary" size="sm" wire:click="exportWalletLedgerCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Total credits (filtered)</div>
            <div class="text-xl font-semibold text-green-700 font-mono">{{ $currencySymbol }}{{ number_format($totalCredit, 2) }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Total debits (filtered)</div>
            <div class="text-xl font-semibold text-red-700 font-mono">{{ $currencySymbol }}{{ number_format($totalDebit, 2) }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Net (filtered)</div>
            <div class="text-xl font-semibold font-mono">{{ $currencySymbol }}{{ number_format($totalCredit - $totalDebit, 2) }}</div>
        </x-ui.card>
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

    <x-ui.table>
        <x-slot:footer>{{ $transactions->links() }}</x-slot:footer>

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
                        <x-ui.badge :color="$t->is_credit ? 'green' : 'red'">{{ $t->is_credit ? 'Credit' : 'Debit' }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-2 text-gray-500">{{ $t->reason }}</td>
                    <td class="px-4 py-2 text-gray-400 font-mono text-xs">{{ $t->ref }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="match($t->status) { 'successful' => 'green', 'pending' => 'amber', 'failed' => 'red', default => 'gray' }">{{ ucfirst($t->status) }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-2 text-gray-500">{{ app(\App\Services\TimezoneResolver::class)->format($t->created_at, $t->wallet?->user?->franchise, 'd M Y, h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No wallet transactions match your filters.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
