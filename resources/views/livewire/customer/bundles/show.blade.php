{{--
    One booking bundle checked out from the services cart. Children link to
    their own order pages; payment (online) and cancellation go through the
    existing bundle services.
--}}
<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8 mb-bottom-nav">

    <a href="{{ route('customer.orders.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-900">
        <x-icon name="arrow-left" class="h-4 w-4" /> Orders
    </a>

    <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Your bundle</h1>
    <p class="mt-1 text-sm text-slate-600">
        {{ $children->count() }} {{ \Illuminate\Support\Str::plural('service', $children->count()) }} ·
        status: <span class="font-medium text-slate-900">{{ ucfirst(str_replace('_', ' ', $derivedStatus)) }}</span> ·
        payment: <span class="font-medium text-slate-900">{{ ucfirst($bundle->payment_status) }}</span>
    </p>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    <div class="mt-4 rounded-xl border border-slate-200 p-4">
        <div class="flex items-baseline justify-between">
            <span class="text-sm font-medium text-slate-700">Bundle total</span>
            <span class="text-xl font-bold text-slate-900">{{ $currencySymbol }}{{ number_format((float) $bundle->total_price_quoted, 2) }}</span>
        </div>

        @if ($bundle->payment_status !== 'paid' && $bundle->payment_method !== 'wallet')
            <button type="button" wire:click="payNow"
                    class="mt-3 flex min-h-12 w-full items-center justify-center rounded-lg bg-slate-900 px-6 text-sm font-semibold text-white transition hover:bg-slate-800">
                Pay now
            </button>
        @endif
    </div>

    <ul class="mt-6 divide-y divide-slate-100 rounded-xl border border-slate-200">
        @foreach ($children as $child)
            <li class="flex items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <a href="{{ route('customer.orders.show', $child) }}" wire:navigate class="block truncate text-sm font-medium text-slate-900 hover:underline">
                        {{ $child->service->name }}
                    </a>
                    <p class="text-xs text-slate-500">
                        {{ $child->service->category?->name }} ·
                        {{ $child->scheduled_at ? $child->scheduled_at->format('j M, g:i A') : 'ASAP' }} ·
                        {{ ucfirst(str_replace('_', ' ', $child->status)) }}
                    </p>
                </div>
                <span class="shrink-0 text-sm font-semibold text-slate-900">
                    {{ $currencySymbol }}{{ number_format((float) $child->price_quoted, 2) }}
                </span>
            </li>
        @endforeach
    </ul>

    @if (! in_array($derivedStatus, ['cancelled', 'completed'], true))
        <button type="button" wire:click="cancelBundle"
                wire:confirm="Cancel every service in this bundle?"
                class="mt-6 text-sm font-medium text-slate-500 underline underline-offset-2 hover:text-rose-600">
            Cancel bundle
        </button>
    @endif

    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
        document.addEventListener('livewire:init', () => {
            const open = (o) => {
                if (!o || !window.Razorpay) return;
                new window.Razorpay({
                    key: o.razorpay_key_id ?? o.key_id,
                    order_id: o.razorpay_order_id,
                    amount: o.amount,
                    currency: o.currency,
                    name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                    description: 'Service bundle',
                    handler: () => window.location.reload(),
                }).open();
            };

            Livewire.on('bundle-pay-open', (e) => open(e.order ?? e[0]?.order));

            @if ($autoPay)
                setTimeout(() => Livewire.dispatch('$refresh'), 0);
                @this.call('payNow');
            @endif
        });
    </script>
</div>
