{{-- REF 1CF-PROMPT-20260925-EARN3 — Earnings → Wallet. Balance is the stored
     wallet balance; history is labelled by WalletSourceLabel; Add money only
     while wallet.topup_enabled is on (the service re-checks everything). --}}
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    @include('livewire.customer.earnings._tabs')

    <div class="relative mt-4 overflow-hidden rounded-3xl bg-gradient-to-br from-blue-700 via-blue-600 to-blue-800 p-6 text-white shadow-xl shadow-blue-900/20">
        <div aria-hidden="true" class="pointer-events-none absolute -right-12 -top-16 h-52 w-52 rounded-full bg-white/10 blur-3xl"></div>
        <p class="relative text-sm text-blue-100">Wallet balance</p>
        <p class="relative mt-1 text-3xl font-bold">{{ $currencySymbol }}{{ number_format($balance, 2) }}</p>
    </div>

    @if ($frozen)
        <div role="alert" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Your wallet is on hold. You can't pay from it, add money or redeem points right now — refunds still arrive. Please contact support.
        </div>
    @endif

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    @if ($topUpOn && ! $frozen)
        <div class="mt-4 rounded-xl border border-slate-200 p-4">
            <p class="text-sm font-semibold">Add money</p>
            <div class="mt-2 flex flex-wrap items-start gap-2">
                <div>
                    <label for="topUpAmount" class="sr-only">Top-up amount</label>
                    <input id="topUpAmount" wire:model="topUpAmount" inputmode="decimal" placeholder="Amount"
                           class="w-36 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-600">
                    @error('topUpAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <button wire:click="requestTopUp"
                        class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    Add money
                </button>
            </div>
            @unless ($gatewayConfigured)
                <p class="mt-2 text-xs text-slate-500">Online top-up isn't available in this environment.</p>
            @endunless
        </div>
    @endif

    <h2 class="mt-6 text-sm font-semibold text-slate-700">Wallet history</h2>
    <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200">
        @forelse ($ledger as $txn)
            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-medium text-slate-800">{{ $this->label($txn->ref) }}</p>
                    <p class="truncate text-xs text-slate-500">{{ $txn->reason }}</p>
                    <p class="text-xs text-slate-400">
                        {{ app(\App\Services\TimezoneResolver::class)->format($txn->created_at, null, 'j M Y, g:i A') }}
                        @if (isset($codes[$txn->ref])) · {{ $codes[$txn->ref] }} @endif
                        @if ($txn->status && $txn->status !== 'successful') · {{ $txn->status }} @endif
                    </p>
                </div>
                <span @class(['shrink-0 font-medium', 'text-emerald-600' => $txn->is_credit, 'text-slate-900' => ! $txn->is_credit])>
                    {{ $txn->is_credit ? '+' : '−' }}{{ $currencySymbol }}{{ number_format((float) $txn->amount, 2) }}
                </span>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500">No wallet activity yet.</li>
        @endforelse
    </ul>
    <div class="mt-3">{{ $ledger->links() }}</div>

    {{-- @script (not a 'livewire:init' listener) so the handler is bound on
         every component init, including after a wire:navigate visit. --}}
    @if ($gatewayConfigured && $topUpOn)
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
                    description: 'Wallet top-up',
                    handler: () => window.location.reload(),
                }).open();
            });
        </script>
        @endscript
    @endif
</div>
