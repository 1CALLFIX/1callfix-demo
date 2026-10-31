<div>
    @if ($count > 0)
        <div class="fixed inset-x-0 bottom-16 z-30 px-4 sm:bottom-6">
            <a href="{{ route('customer.cart') }}" wire:navigate
               class="mx-auto flex max-w-xl items-center justify-between rounded-2xl bg-slate-900 px-5 py-3 text-white shadow-xl">
                <span class="text-sm"><span class="font-bold">{{ $count === 1 ? \App\Support\SiteText::get('cart.bar_one') : \App\Support\SiteText::get('cart.bar_many', ['count' => $count]) }}</span> · {{ $currencySymbol }}{{ number_format($total, 2) }}</span>
                <span class="text-sm font-bold">{{ \App\Support\SiteText::get('cart.bar_view') }}</span>
            </a>
        </div>
    @endif
</div>
