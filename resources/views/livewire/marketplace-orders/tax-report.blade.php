<div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Shop Tax Report</h1>
            <p class="text-sm text-gray-500">GST TCS and income-tax TDS withheld from sellers, by month. Rates are set in Shop Settings → Tax compliance (from the CA).</p>
        </div>
        <div class="flex items-end gap-2">
            <label class="text-sm">Month<input type="month" wire:model.live="month" class="mt-1 block rounded border px-2 py-1.5"></label>
            <x-ui.button wire:click="download">Download CSV</x-ui.button>
        </div>
    </div>

    <x-ui.table>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr><th class="px-4 py-2">Shop</th><th class="px-4 py-2">GSTIN</th><th class="px-4 py-2">PAN</th><th class="px-4 py-2 text-right">Orders</th><th class="px-4 py-2 text-right">Taxable value</th><th class="px-4 py-2 text-right">GST TCS</th><th class="px-4 py-2 text-right">TDS 194-O</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $r->name }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ $r->gstin ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ $r->pan ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">{{ $r->orders }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">₹{{ number_format((float) $r->taxable, 2) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">₹{{ number_format((float) $r->tcs, 2) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">₹{{ number_format((float) $r->tds, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">No completed shop orders this month.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
