<div class="mb-bottom-nav">
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
        <h1 class="text-2xl font-bold tracking-tight">Shop orders</h1>
        <div class="mt-4 space-y-3">
            @forelse ($orders as $o)
                <a href="{{ route('customer.shop.orders.show', $o) }}" wire:navigate class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 hover:border-blue-300">
                    @if ($o->store?->logo_url)
                        <img src="{{ $o->store->logo_url }}" alt="" class="h-12 w-12 rounded-xl object-cover">
                    @else
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-50 font-bold text-blue-700">{{ mb_substr($o->store?->name ?? '?', 0, 1) }}</span>
                    @endif
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold">{{ $o->store?->name }}</span>
                        <span class="block text-xs text-slate-500">{{ $o->code }} · {{ $o->items->sum('quantity') }} items · {{ $o->created_at->format('d M, h:i A') }}</span>
                    </span>
                    <span class="text-right">
                        <span class="block font-bold">{{ $currencySymbol }}{{ number_format((float) $o->total_amount, 2) }}</span>
                        <x-shop.order-status :order="$o" class="mt-1" />
                    </span>
                </a>
            @empty
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">No shop orders yet.</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $orders->links() }}</div>
    </div>
</div>
