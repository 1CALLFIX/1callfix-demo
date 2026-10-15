<div>
    <h1 class="text-xl font-bold tracking-tight">Payments</h1>

    <div class="mt-3 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('provider.earnings') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-white text-slate-700 border border-slate-300">Earnings</a>
        <a href="{{ route('provider.payment-accounts') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-white text-slate-700 border border-slate-300">Payment Accounts</a>
        <a href="{{ route('provider.request-payout') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-white text-slate-700 border border-slate-300">Request Payout</a>
        <a href="{{ route('provider.wallet') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-slate-900 text-white">Wallet &amp; dues</a>
    </div>

    @if ($flashMessage)
        <div @class(['mt-4 rounded-lg p-3 text-sm', 'bg-emerald-50 text-emerald-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    @if ($standing['blockers'] === [])
        <div class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">You can take new jobs.</div>
    @else
        <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
            <p class="font-semibold">You are not getting new jobs right now:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($standing['blockers'] as $blocker)
                    <li>{{ $blocker['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <x-ui.card class="!p-4">
            <p class="text-xs font-semibold uppercase text-gray-500">Wallet balance</p>
            <p class="mt-1 text-2xl font-bold">{{ $currency }}{{ number_format($standing['balance'], 2) }}</p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-xs font-semibold uppercase text-gray-500">Unpaid cash commission</p>
            <p class="mt-1 text-2xl font-bold">{{ $currency }}{{ number_format($standing['dues'], 2) }}</p>
            @if ($standing['max_dues'] !== null)
                <p class="mt-1 text-xs text-gray-500">Limit {{ $currency }}{{ number_format($standing['max_dues'], 2) }}</p>
            @endif
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-xs font-semibold uppercase text-gray-500">Minimum balance</p>
            <p class="mt-1 text-2xl font-bold">{{ $standing['floor'] === null ? 'Not required' : $currency.number_format($standing['floor'], 2) }}</p>
            @if ($standing['floor'] !== null && $standing['floor_counts_dues'])
                <p class="mt-1 text-xs text-gray-500">Unpaid commission is counted against it.</p>
            @endif
        </x-ui.card>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @if ($standing['dues'] > 0 && $standing['balance'] > 0)
            <x-ui.button variant="secondary" wire:click="settleFromWallet">Settle dues from wallet</x-ui.button>
        @endif
        @if ($standing['pay_to_restore'] > 0 && $gatewayConfigured)
            <x-ui.button wire:click="payNow">Pay {{ $currency }}{{ number_format($standing['pay_to_restore'], 2) }} now</x-ui.button>
        @endif
    </div>
    @if ($standing['pay_to_restore'] > 0)
        <p class="mt-2 text-xs text-gray-500">This clears all unpaid commission{{ $standing['floor'] !== null ? ' and keeps the minimum balance in your wallet' : '' }}. Payments are applied to your oldest dues first.</p>
    @endif

    <x-ui.card class="!p-0 mt-4">
        <div class="px-4 py-3 text-sm font-semibold">Unpaid cash commission by job</div>
        <ul class="divide-y text-sm">
            @forelse ($receivables as $r)
                <li class="flex justify-between px-4 py-2">
                    <span>{{ $r->booking?->code ?? 'Job #'.$r->booking_id }}</span>
                    <span>{{ $currency }}{{ number_format((float) $r->amount_owed - (float) $r->amount_settled, 2) }}</span>
                </li>
            @empty
                <li class="px-4 py-4 text-center text-slate-500">Nothing owed.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($gatewayConfigured)
        @assets
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        @endassets
        @script
        <script>
            $wire.on('razorpay-open', (e) => {
                const o = e.order ?? e[0]?.order;
                if (!o || !window.Razorpay) return;
                new window.Razorpay({
                    key: o.razorpay_key_id ?? o.key_id,
                    order_id: o.razorpay_order_id,
                    amount: o.amount,
                    currency: o.currency,
                    name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                    description: 'Dues payment',
                    handler: () => window.location.reload(),
                }).open();
            });
        </script>
        @endscript
    @endif
</div>
