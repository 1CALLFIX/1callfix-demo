<div>
    <h1 class="text-xl font-bold tracking-tight">Earnings</h1>

    {{-- Earnings / Payment Accounts live under this one nav branch — see
         the matching strip in payment-accounts.blade.php. --}}
    <div class="mt-3 flex gap-2 text-sm">
        <a href="{{ route('provider.earnings') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-slate-900 text-white">Earnings</a>
        <a href="{{ route('provider.payment-accounts') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-white text-slate-700 border border-slate-300">Payment Accounts</a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3">
        <x-ui.card class="!p-4">
            <p class="text-xs font-semibold uppercase text-gray-500">Wallet balance</p>
            <p class="mt-1 text-2xl font-bold">₹{{ number_format((float) $walletBalance, 2) }}</p>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <p class="text-xs font-semibold uppercase text-gray-500">
                Earned ({{ $range === 'all' ? 'all time' : ('past '.$range) }})
            </p>
            <p class="mt-1 text-2xl font-bold">₹{{ number_format($rangeTotal, 2) }}</p>
            <p class="text-xs text-slate-500">{{ $jobsInRange }} {{ \Illuminate\Support\Str::plural('job', $jobsInRange) }}</p>
        </x-ui.card>
    </div>

    <div class="mt-4 flex gap-2 text-sm">
        @foreach (['week' => 'Week', 'month' => 'Month', 'all' => 'All'] as $key => $label)
            <button type="button" wire:click="setRange('{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 font-medium',
                        'bg-slate-900 text-white' => $range === $key,
                        'bg-white text-slate-700 border border-slate-300' => $range !== $key,
                    ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="mt-4 space-y-2">
        @forelse ($rows as $row)
            <x-ui.card class="!p-4">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <div>
                        <p class="font-medium">{{ $row->booking?->code ?? 'Job' }}</p>
                        <p class="text-xs text-slate-500">
                            {{ str_replace('_', ' ', $row->booking?->status ?? '') }}
                            @if ($row->booking?->completed_at) · {{ \Illuminate\Support\Carbon::parse($row->booking->completed_at)->format('j M Y') }} @endif
                        </p>
                    </div>
                    <p class="font-semibold text-emerald-700">+₹{{ number_format((float) $row->provider_commission, 2) }}</p>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="!p-6 text-center text-sm text-slate-500">No earnings in this period.</x-ui.card>
        @endforelse
    </div>
</div>
