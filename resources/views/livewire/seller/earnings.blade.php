@php $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2); @endphp
<div>
    <h1 class="text-2xl font-bold tracking-tight">Earnings</h1>
    @include('livewire.provider.partials.payments-tabs', ['area' => 'seller', 'current' => 'earnings'])

    @if ($notice)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ $notice }}</div>
    @endif

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ready to withdraw</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $fmt($balance) }}</p>
            <p class="mt-1 text-xs text-slate-600">Your wallet. Add a bank or UPI account, then request a payout once it is verified.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">On hold</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $fmt($held) }}</p>
            <p class="mt-1 text-xs text-slate-600">Paid into your wallet once each order's return window ends.</p>
        </div>
        <div @class(['rounded-2xl border p-4', 'border-rose-200 bg-rose-50' => $owed > 0, 'border-slate-200 bg-white' => $owed <= 0])>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Commission owed</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $fmt($owed) }}</p>
            <p class="mt-1 text-xs text-slate-600">On a cash-at-pickup order you keep the cash and owe the commission. It is taken from your wallet before any payout.</p>
            @if ($owed > 0 && $balance > 0)
                <button type="button" wire:click="settle" class="mt-3 inline-flex min-h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">Settle from wallet now</button>
            @endif
        </div>
    </div>

    <h2 class="mt-6 text-base font-semibold">Orders</h2>
    <div class="mt-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr><th class="px-4 py-2">Order</th><th class="px-4 py-2">Paid by</th><th class="px-4 py-2 text-right">Goods</th><th class="px-4 py-2 text-right">Your share</th><th class="px-4 py-2">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($settlements as $row)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $row->marketplaceOrder?->code }}</td>
                        <td class="px-4 py-2">{{ ['online' => 'Online / wallet', 'cash_delivery' => 'Cash on delivery', 'cash_pickup' => 'Cash at pickup'][$row->payment_mode] ?? ucfirst((string) $row->payment_mode) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $fmt($row->goods_amount) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $fmt($row->seller_share) }}</td>
                        <td class="px-4 py-2">
                            @if ($row->status === 'released')
                                <span class="font-semibold text-emerald-700">In wallet</span>
                            @else
                                <span class="text-amber-700">On hold until {{ optional($row->release_at)->format('d M') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-slate-500">No completed orders yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $settlements->links() }}</div>
</div>
