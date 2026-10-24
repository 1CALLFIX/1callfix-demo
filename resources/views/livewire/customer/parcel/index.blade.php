@php
    $labels = [
        'pending' => ['Booked', 'bg-slate-100 text-slate-700'],
        'searching_worker' => ['Finding rider', 'bg-amber-100 text-amber-800'],
        'assigned' => ['Rider assigned', 'bg-blue-100 text-blue-800'],
        'worker_en_route_pickup' => ['Rider on the way', 'bg-blue-100 text-blue-800'],
        'picked_up' => ['On the way', 'bg-blue-100 text-blue-800'],
        'en_route_dropoff' => ['On the way', 'bg-blue-100 text-blue-800'],
        'delivered' => ['Delivered', 'bg-emerald-100 text-emerald-800'],
        'cancelled' => ['Cancelled', 'bg-rose-100 text-rose-800'],
        'disputed' => ['Returning to you', 'bg-amber-100 text-amber-800'],
    ];
    $tz = app(\App\Services\TimezoneResolver::class);
@endphp

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">My parcels</h1>
        @if ($canSend)
            <a href="{{ route('customer.parcels.create') }}" wire:navigate class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">Send a parcel</a>
        @endif
    </div>

    @forelse ($orders as $order)
        @php [$label, $classes] = $order->isAwaitingOnlinePayment() ? ['Payment pending', 'bg-amber-100 text-amber-800'] : ($labels[$order->status] ?? [ucfirst($order->status), 'bg-slate-100 text-slate-700']); @endphp
        <a href="{{ route('customer.parcels.show', $order) }}" wire:navigate
           class="mt-3 block rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 hover:bg-slate-50">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate font-semibold text-slate-900">To {{ $order->recipient_name ?: 'recipient' }}</p>
                    <p class="truncate text-xs text-slate-500">{{ $order->code }} · {{ $tz->format($order->created_at, $order->franchise, 'j M Y') }} · {{ $order->dropoffAddress?->address_line }}</p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $classes }}">{{ $label }}</span>
            </div>
            <p class="mt-2 text-right text-sm font-medium text-slate-900">{{ $currencySymbol }}{{ number_format((float) ($order->price_final ?? $order->price_quoted), 2) }}</p>
        </a>
    @empty
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 p-8 text-center">
            <p class="text-sm text-slate-600">No parcels yet.</p>
        </div>
    @endforelse

    <div class="mt-5">{{ $orders->links() }}</div>
</div>
