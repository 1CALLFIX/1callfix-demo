<div>
    <h1 class="text-xl font-bold tracking-tight">Payments</h1>

    {{-- Earnings / Payment Accounts / Request Payout live under this one nav
         branch — see the matching strip in earnings.blade.php and
         payment-accounts.blade.php. --}}
    <div class="mt-3 flex gap-2 text-sm">
        <a href="{{ route('provider.earnings') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-white text-slate-700 border border-slate-300">Earnings</a>
        <a href="{{ route('provider.payment-accounts') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-white text-slate-700 border border-slate-300">Payment Accounts</a>
        <a href="{{ route('provider.request-payout') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-slate-900 text-white">Request Payout</a>
    </div>

    @if ($flashMessage)
        <div @class(['mt-4 rounded-lg p-3 text-sm', 'bg-emerald-50 text-emerald-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="mt-4">
        <x-ui.card class="!p-4">
            <p class="text-xs font-semibold uppercase text-gray-500">Wallet balance</p>
            <p class="mt-1 text-2xl font-bold">₹{{ number_format((float) $walletBalance, 2) }}</p>
        </x-ui.card>
    </div>

    @if ($kyc['restricted'])
        <div class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-700">
            Withdrawals are temporarily restricted until KYC is completed. Contact your Franchise Office for assistance.
        </div>
    @endif

    <div class="mt-4">
        @if ($verifiedAccounts->isEmpty())
            <x-ui.card class="!p-6 text-center text-sm text-slate-500">
                You don't have a verified payment account yet. Add one from the
                <a href="{{ route('provider.payment-accounts') }}" wire:navigate class="text-blue-600 hover:underline">Payment Accounts</a>
                tab and wait for admin verification before requesting a payout.
            </x-ui.card>
        @else
            <x-ui.card class="!p-4">
                <h2 class="text-sm font-semibold">Request a payout</h2>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Payment account</label>
                        <select wire:model="paymentAccountId" class="w-full rounded border px-3 py-2 text-sm" @disabled($kyc['restricted'])>
                            @foreach ($verifiedAccounts as $account)
                                <option value="{{ $account->id }}">
                                    {{ strtoupper($account->account_type) }} — {{ $account->masked_account_number }}{{ $account->is_default ? ' (default)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('paymentAccountId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Amount</label>
                        <input type="number" step="0.01" wire:model="amount" class="w-full rounded border px-3 py-2 text-sm" @disabled($kyc['restricted'])>
                        @error('amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-slate-500">
                            @if ($minPayout > 0) Minimum ₹{{ number_format($minPayout, 2) }}. @endif
                            @if ($maxPayout > 0) Maximum ₹{{ number_format($maxPayout, 2) }}. @endif
                        </p>
                    </div>
                </div>

                <div class="mt-4">
                    <x-ui.button wire:click="request" :disabled="$kyc['restricted']">Request Payout</x-ui.button>
                </div>
            </x-ui.card>
        @endif
    </div>

    <div class="mt-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase">Payout history</h2>
        <div class="mt-2 space-y-2">
            @forelse ($payouts as $payout)
                @php
                    $statusColor = match ($payout->status) {
                        'paid' => 'green',
                        'processing' => 'blue',
                        'failed' => 'red',
                        default => 'amber',
                    };
                @endphp
                <x-ui.card class="!p-4" wire:key="payout-{{ $payout->id }}">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">₹{{ number_format((float) $payout->amount, 2) }}</p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $payout->paymentAccount?->masked_account_number ?? 'No account on file' }}
                                · Requested {{ $payout->created_at->format('j M Y') }}
                                @if ($payout->status === 'paid' && $payout->gateway_ref) · Ref {{ $payout->gateway_ref }} @endif
                            </p>
                        </div>
                        <x-ui.badge :color="$statusColor">{{ ucfirst($payout->status) }}</x-ui.badge>
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card class="!p-6 text-center text-sm text-slate-500">No payout requests yet.</x-ui.card>
            @endforelse
        </div>
    </div>
</div>
