<div>
    @if ($count > 0)
        <div class="fixed inset-x-0 bottom-16 z-30 px-4 sm:bottom-6">
            <a href="{{ route('customer.shop.cart') }}" wire:navigate
               class="mx-auto flex max-w-xl items-center justify-between rounded-2xl bg-slate-900 px-5 py-3 text-white shadow-xl">
                <span class="text-sm"><span class="font-bold">{{ $count }} {{ $count === 1 ? 'item' : 'items' }}</span> · {{ $currencySymbol }}{{ number_format($total, 2) }}</span>
                <span class="text-sm font-bold">View bag →</span>
            </a>
        </div>
    @endif
</div>
