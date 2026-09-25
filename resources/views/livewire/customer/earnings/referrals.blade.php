{{-- REF 1CF-PROMPT-20260925-EARN3 — Earnings → Referrals (read-only). --}}
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    @include('livewire.customer.earnings._tabs')

    <div class="mt-4 rounded-xl border border-slate-200 p-4">
        <p class="text-sm text-slate-500">Your referral code</p>
        <p class="mt-1 font-mono text-2xl font-bold tracking-wider">{{ $code ?: '—' }}</p>
        @unless ($code)
            <p class="mt-1 text-xs text-slate-500">A referral code hasn't been assigned to your account yet.</p>
        @endunless
    </div>

    <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
        <p class="font-semibold text-slate-800">How referrals work</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @if ($programOn && $maxPerCustomer !== null && (($rewardType === 'wallet' && $rewardAmount !== null) || ($rewardType === 'points' && $rewardPoints !== null)))
                <li>When someone you refer completes their first booking, you get
                    {{ $rewardType === 'wallet' ? $currencySymbol.number_format($rewardAmount, 2).' in your wallet' : number_format($rewardPoints).' loyalty points' }}.</li>
                <li>Up to {{ number_format($maxPerCustomer) }} rewarded referrals per customer.</li>
            @else
                <li>Referral rewards are currently paused.</li>
            @endif
        </ul>
    </div>

    <h2 class="mt-6 text-sm font-semibold text-slate-700">Your referrals</h2>
    <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200">
        @forelse ($referrals as $r)
            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-medium text-slate-800">{{ \Illuminate\Support\Str::of($r->referred->name ?? 'Friend')->before(' ') }}</p>
                    <p class="text-xs text-slate-400">{{ app(\App\Services\TimezoneResolver::class)->format($r->created_at, null, 'j M Y') }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-xs font-medium">{{ ['pending' => 'Pending', 'rewarded' => 'Rewarded', 'expired' => 'Expired', 'fraud_flagged' => 'Reversed'][$r->status] ?? ucfirst($r->status) }}</p>
                    @if ($r->status === 'rewarded' && (float) $r->reward_amount > 0)
                        <p class="text-xs text-emerald-600">+{{ $currencySymbol }}{{ number_format((float) $r->reward_amount, 2) }}</p>
                    @endif
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500">No referrals yet.</li>
        @endforelse
    </ul>
    <div class="mt-3">{{ $referrals->links() }}</div>
</div>
