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

        @if ((float) ($bundle->coupon_discount_amount ?? 0) > 0)
            <dl class="mt-2 space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-slate-600">Coupon discount</dt><dd class="text-emerald-700">−{{ $currencySymbol }}{{ number_format((float) $bundle->coupon_discount_amount, 2) }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold text-slate-900"><dt>You pay</dt><dd>{{ $currencySymbol }}{{ number_format($bundle->amountPayable(), 2) }}</dd></div>
            </dl>
        @endif

        @if ($bundle->payment_status !== 'paid' && $bundle->payment_method !== 'wallet')
            <button type="button" wire:click="payNow"
                    class="mt-3 flex min-h-12 w-full items-center justify-center rounded-lg bg-blue-600 px-6 text-sm font-semibold text-white transition hover:bg-blue-700">
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
                        {{ $child->scheduled_at ? app(\App\Services\TimezoneResolver::class)->format($child->scheduled_at, $child->franchise, 'j M, g:i A') : 'ASAP' }} ·
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
        @if ($cancelPreview === null)
            <button type="button" wire:click="reviewCancel"
                    class="mt-6 text-sm font-medium text-slate-500 underline underline-offset-2 hover:text-rose-600">
                Cancel bundle
            </button>
        @else
            {{-- REF 1CF-CANCEL-POLICY-001 step 8 — never a silent partial cancel: show exactly what goes and what stays. --}}
            <section class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 sm:p-5" aria-live="polite" data-testid="bundle-cancel-preview">
                <h2 class="text-sm font-semibold text-rose-900">Review before you cancel</h2>

                @if (! empty($cancelPreview['will_cancel']))
                    <h3 class="mt-3 text-xs font-semibold uppercase text-rose-800">Will be cancelled</h3>
                    <ul class="mt-1 space-y-1 text-sm text-slate-800">
                        @foreach ($cancelPreview['will_cancel'] as $row)
                            <li data-testid="preview-cancel">{{ $row['service'] ?? $row['code'] }} <span class="text-xs text-slate-500">({{ $row['code'] }}) — {{ ($row['charge'] ?? 0) > 0 ? 'charge '.$currencySymbol.number_format($row['charge'], 2).', taken from your payment' : 'no charge' }}</span></li>
                        @endforeach
                    </ul>
                @endif

                @if (! empty($cancelPreview['kept']))
                    <h3 class="mt-3 text-xs font-semibold uppercase text-slate-700">Will stay booked — and why</h3>
                    <ul class="mt-1 space-y-2 text-sm text-slate-800">
                        @foreach ($cancelPreview['kept'] as $row)
                            <li data-testid="preview-kept">{{ $row['service'] ?? $row['code'] }} <span class="text-xs text-slate-500">({{ $row['code'] }})</span><br><span class="text-xs text-slate-600">{{ $row['reason'] }}</span></li>
                        @endforeach
                    </ul>
                @endif

                @if ($cancelPreview['nothing'])
                    <p class="mt-3 text-sm text-rose-900">Nothing in this bundle can be cancelled right now.</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @unless ($cancelPreview['nothing'])
                        <button type="button" wire:click="cancelBundle" wire:loading.attr="disabled"
                                class="min-h-11 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                            {{ empty($cancelPreview['kept']) ? 'Yes, cancel all' : 'Cancel only the visits listed' }}
                        </button>
                    @endunless
                    <button type="button" wire:click="dismissCancel" class="min-h-11 rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">Keep everything</button>
                </div>
            </section>
        @endif
    @endif

    {{-- @script, not a 'livewire:init' listener, so "Pay now" still works
         when this page is reached via wire:navigate. --}}
    @assets
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    @endassets
    @script
    <script>
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

        $wire.on('bundle-pay-open', (e) => open(e.order ?? e[0]?.order));

        @if ($autoPay)
            $wire.payNow();
        @endif
    </script>
    @endscript
</div>
