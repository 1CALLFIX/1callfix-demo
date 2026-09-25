{{-- REF 1CF-PROMPT-20260925-EARN3 — Earnings → Loyalty points. Points only —
     never summed with wallet money. Redeem = preview (server-computed) then
     confirm (server recomputes). --}}
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    @include('livewire.customer.earnings._tabs')

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([['Available', $summary['available']], ['Earned', $summary['earned']], ['Redeemed', $summary['redeemed']], ['Expired', $summary['expired']]] as [$label, $value])
            <div @class(['rounded-xl border p-3', 'border-blue-200 bg-blue-50' => $label === 'Available', 'border-slate-200' => $label !== 'Available'])>
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold">{{ number_format($value) }} <span class="text-xs font-medium text-slate-500">pts</span></p>
            </div>
        @endforeach
    </div>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    @if ($isCustomer && $policy['on'] && $policy['rate'] && $policy['min'] !== null)
        <div class="mt-4 rounded-xl border border-slate-200 p-4">
            <p class="text-sm font-semibold">Redeem points to your wallet</p>
            @if ($previewPoints === null)
                <div class="mt-2 flex flex-wrap items-start gap-2">
                    <div>
                        <label for="redeemPoints" class="sr-only">Points to redeem</label>
                        <input id="redeemPoints" wire:model="redeemPoints" inputmode="numeric" placeholder="Points"
                               class="w-36 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-600">
                        @error('redeemPoints') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <button wire:click="preview" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Review</button>
                </div>
            @else
                <p class="mt-2 text-sm text-slate-700">You'll redeem <strong>{{ number_format($previewPoints) }} points</strong> and receive <strong>{{ $currencySymbol }}{{ number_format($previewRupees, 2) }}</strong> in your wallet.</p>
                <div class="mt-3 flex gap-2">
                    <button wire:click="confirmRedeem" class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Confirm</button>
                    <button wire:click="$set('previewPoints', null)" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 py-2 text-sm">Change</button>
                </div>
            @endif
        </div>
    @endif

    <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
        <p class="font-semibold text-slate-800">How points work</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @if ($earnRate)
                <li>Earn {{ rtrim(rtrim(number_format($earnRate * 100, 2), '0'), '.') }} points for every {{ $currencySymbol }}100 on a completed booking.</li>
            @else
                <li>Points earning is currently paused.</li>
            @endif
            @if ($policy['on'] && $policy['rate'] && $policy['min'] !== null)
                <li>{{ number_format($policy['rate']) }} points = {{ $currencySymbol }}1 when you redeem@if ($policy['min'] > 0), minimum {{ number_format($policy['min']) }} points@endif.</li>
            @else
                <li>Redemption is currently unavailable.</li>
            @endif
            @if ($expiryDays !== null)
                <li>{{ $expiryDays > 0 ? "Points expire {$expiryDays} days after they're earned; the oldest points are used first." : 'Points do not expire.' }}</li>
            @endif
        </ul>
    </div>

    <h2 class="mt-6 text-sm font-semibold text-slate-700">Points history</h2>
    <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200">
        @forelse ($history as $row)
            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-medium text-slate-800">{{ \App\Support\WalletSourceLabel::loyaltyLabel($row) }}</p>
                    <p class="text-xs text-slate-400">
                        {{ app(\App\Services\TimezoneResolver::class)->format($row->created_at, null, 'j M Y') }}
                        @if ($row->booking && $row->booking->customer_id === auth()->id()) · {{ $row->booking->code }} @endif
                        @if ($row->points > 0 && $row->expires_at) · expires {{ app(\App\Services\TimezoneResolver::class)->format($row->expires_at, null, 'j M Y') }} @endif
                    </p>
                </div>
                <span @class(['shrink-0 font-medium', 'text-emerald-600' => $row->points > 0, 'text-slate-900' => $row->points <= 0])>
                    {{ $row->points > 0 ? '+' : '−' }}{{ number_format(abs($row->points)) }} pts
                </span>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500">No points yet.</li>
        @endforelse
    </ul>
    <div class="mt-3">{{ $history->links() }}</div>
</div>
