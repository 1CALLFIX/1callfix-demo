@php $tz = app(\App\Services\TimezoneResolver::class); @endphp
<div>
    <h1 class="text-xl font-bold tracking-tight">History</h1>

    @forelse ($orders as $order)
        <div class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold">{{ $order->code }} · {{ $order->status === 'delivered' ? 'Delivered' : 'Cancelled' }}</p>
                    <p class="truncate text-slate-600">{{ $order->dropoffAddress?->address_line }}</p>
                    <p class="text-xs text-slate-500">{{ $tz->format($order->delivered_at ?? $order->updated_at, $order->franchise, 'j M Y, g:i A') }} · {{ $order->payment_method === 'cash' ? 'Cash' : 'Prepaid' }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="font-semibold tabular-nums">{{ $currencySymbol }}{{ number_format((float) ($order->price_final ?? $order->price_quoted), 2) }}</p>
                    @if ($order->commission)
                        <p class="text-xs text-emerald-700">You earn {{ $currencySymbol }}{{ number_format((float) $order->commission->provider_commission, 2) }}</p>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">No finished parcels yet.</p>
    @endforelse

    <div class="mt-5">{{ $orders->links() }}</div>
</div>
