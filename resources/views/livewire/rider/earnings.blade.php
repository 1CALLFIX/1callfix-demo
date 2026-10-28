@php $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2); @endphp
<div>
    <h1 class="text-xl font-bold tracking-tight">Earnings</h1>
    @include('livewire.provider.partials.payments-tabs', ['area' => 'rider', 'current' => 'earnings'])

    @if ($notice)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ $notice }}</div>
    @endif

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Wallet balance</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $fmt($balance) }}</p>
            <p class="mt-1 text-xs text-slate-600">Your share of every prepaid parcel lands here on delivery. Add a bank or UPI account, then request a payout once it is verified.</p>
        </div>
        <div @class(['rounded-2xl border p-4', 'border-rose-200 bg-rose-50' => $owed > 0, 'border-slate-200 bg-white' => $owed <= 0])>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cash dues</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $fmt($owed) }}</p>
            <p class="mt-1 text-xs text-slate-600">On a cash parcel you keep the cash and owe the platform's share. It is taken from your wallet automatically when online earnings arrive.</p>
            @if ($owed > 0 && $balance > 0)
                <button type="button" wire:click="settle" class="mt-3 inline-flex min-h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">Settle from wallet now</button>
            @endif
        </div>
    </div>

    @if ($openDues->isNotEmpty())
        <h2 class="mt-6 text-base font-semibold">Open dues</h2>
        <ul class="mt-2 divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white text-sm">
            @foreach ($openDues as $due)
                <li class="flex justify-between gap-3 px-4 py-3">
                    <span>Parcel {{ $due->parcelOrder?->code }}</span>
                    <span class="tabular-nums">{{ $fmt($due->outstandingAmount()) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
