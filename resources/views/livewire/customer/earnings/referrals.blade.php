{{-- REF 1CF-PROMPT-20260925-EARN4 — Earnings → Referrals. Own data only,
     via ReferralPresenter (the same view the API returns). Hidden entirely
     (404) while earnings.referral_tab or referral.enabled is off. --}}
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    @include('livewire.customer.earnings._tabs')

    <div class="mt-4 rounded-xl border border-slate-200 p-4">
        <p class="text-sm text-slate-500">Your referral code</p>
        <p class="mt-1 font-mono text-2xl font-bold tracking-wider">{{ $code ?: '—' }}</p>

        @if ($shareLink)
            @php
                $shareText = "Join me on {$platformName} — sign up with my code {$code}: {$shareLink}";
            @endphp
            <div class="mt-3" x-data="{ copied: false }">
                <label for="ref-link" class="block text-xs font-medium text-slate-500">Your share link</label>
                <div class="mt-1 flex gap-2">
                    <input id="ref-link" type="text" readonly value="{{ $shareLink }}"
                           class="block min-h-11 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
                    <button type="button"
                            x-on:click="navigator.clipboard.writeText(@js($shareLink)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                            class="inline-flex min-h-11 shrink-0 items-center rounded-lg border border-slate-300 px-3 text-sm font-medium hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        <span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
                <a href="https://wa.me/?text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener"
                   class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                    Share on WhatsApp
                </a>
            </div>
        @else
            <p class="mt-1 text-xs text-slate-500">A referral code hasn't been assigned to your account yet.</p>
        @endif
    </div>

    <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
        <p class="font-semibold text-slate-800">How referrals work</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @if ($rules['reward_type'] && ($rules['reward_amount'] !== null || $rules['reward_points'] !== null) && $rules['max_rewarded_referrals'] !== null)
                <li>When a friend you refer completes a booking paid online (cash jobs do not count), you get
                    {{ $rules['reward_type'] === 'wallet' ? $currencySymbol.number_format($rules['reward_amount'], 2).' in your wallet' : number_format($rules['reward_points']).' loyalty points' }}.</li>
                <li>Up to {{ number_format($rules['max_rewarded_referrals']) }} rewarded referrals per customer.</li>
            @else
                <li>Referral rewards are currently paused.</li>
            @endif
            @if ($rules['friend_reward_type'] && ($rules['friend_reward_amount'] !== null || $rules['friend_reward_points'] !== null))
                <li>Your friend gets {{ $rules['friend_reward_type'] === 'wallet' ? $currencySymbol.number_format($rules['friend_reward_amount'], 2).' in their wallet' : number_format($rules['friend_reward_points']).' loyalty points' }} too.</li>
            @endif
            <li>Your friend must enter your code when they create their account — it can't be added later.</li>
        </ul>
    </div>

    <h2 class="mt-6 text-sm font-semibold text-slate-700">Your referrals</h2>
    <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200">
        @forelse ($referrals as $r)
            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-medium text-slate-800">{{ $r['friend'] }}</p>
                    <p class="text-xs text-slate-400">{{ $r['joined_at'] ? app(\App\Services\TimezoneResolver::class)->format(\Illuminate\Support\Carbon::parse($r['joined_at']), null, 'j M Y') : '' }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-xs font-medium">{{ $r['status_label'] }}</p>
                    @if ($r['reward_amount'])
                        <p class="text-xs text-emerald-600">+{{ $currencySymbol }}{{ number_format($r['reward_amount'], 2) }}</p>
                    @elseif ($r['reward_points'])
                        <p class="text-xs text-emerald-600">+{{ number_format($r['reward_points']) }} pts</p>
                    @endif
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500">No referrals yet.</li>
        @endforelse
    </ul>
    <div class="mt-3">{{ $referrals->links() }}</div>
</div>
