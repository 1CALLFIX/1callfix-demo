@php
    $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 0);
    $km = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $tz = app(\App\Services\TimezoneResolver::class)->platformTimezone();
@endphp

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    <a href="{{ route('customer.parcels.create') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
        <x-icon name="arrow-left" class="h-4 w-4" /> Send a parcel
    </a>
    <h1 class="mt-3 text-2xl font-bold tracking-tight">Parcel packs</h1>
    <x-customer.share-button :title="'Parcel packs'" class="mt-3" />
    <p class="mt-1 text-sm text-slate-600">Prepaid parcels for shops and offices. Each parcel uses one credit; anything beyond what the pack covers is paid online. Pack parcels are paid online or from the wallet, not in cash.</p>

    @if ($notice)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ $error }}</div>
    @endif

    @if ($mine->isNotEmpty())
        <h2 class="mt-6 text-base font-semibold">Your packs</h2>
        <div class="mt-2 space-y-2">
            @foreach ($mine as $p)
                <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm" wire:key="mine-{{ $p->id }}">
                    <div>
                        <p class="font-semibold">{{ $p->name }}</p>
                        <p class="text-slate-600">Up to {{ $km($p->max_km) }} km each · {{ $p->expires_at?->isPast() ? 'expired' : 'valid till '.$p->expires_at?->timezone($tz)->format('j M Y') }}</p>
                    </div>
                    <p class="shrink-0 text-right"><span class="text-xl font-bold tabular-nums">{{ $p->remaining() }}</span><span class="block text-xs text-slate-500">of {{ $p->parcels_total }} left</span></p>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="mt-6 text-base font-semibold">Buy a pack</h2>
    @forelse ($onSale as $pack)
        <div class="mt-2 rounded-2xl border border-slate-200 bg-white p-4" wire:key="pack-{{ $pack->id }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="font-semibold">{{ $pack->name }}</p>
                    <p class="text-sm text-slate-600">{{ $pack->parcel_count }} parcels · up to {{ $km($pack->max_km) }} km each · valid {{ $pack->validity_days }} days</p>
                    <p class="text-xs text-slate-500">{{ $fmt((float) $pack->price / max(1, $pack->parcel_count)) }} a parcel</p>
                </div>
                <p class="shrink-0 text-lg font-bold tabular-nums">{{ $fmt($pack->price) }}</p>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="buy({{ $pack->id }}, 'online')" wire:loading.attr="disabled" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">Pay online</button>
                @if ($walletOn)
                    <button type="button" wire:click="buy({{ $pack->id }}, 'wallet')" wire:confirm="Pay {{ $fmt($pack->price) }} from your wallet?" wire:loading.attr="disabled" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50">Pay from wallet</button>
                @endif
            </div>
        </div>
    @empty
        <p class="mt-2 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">No parcel packs are on sale here yet.</p>
    @endforelse

    @assets
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    @endassets
    @script
    <script>
        $wire.on('razorpay-open', (e) => {
            const o = e.order ?? e[0]?.order;
            if (! o || ! window.Razorpay) return;
            new window.Razorpay({
                key: o.razorpay_key_id,
                order_id: o.razorpay_order_id,
                amount: o.amount,
                currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                description: 'Parcel pack',
                handler: () => window.location.reload(),
            }).open();
        });
    </script>
    @endscript
</div>
