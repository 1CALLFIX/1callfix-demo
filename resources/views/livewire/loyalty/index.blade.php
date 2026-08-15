<div>
    <h1 class="text-2xl font-bold mb-4">Loyalty & Referrals</h1>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="flex gap-1 mb-4 border-b">
        <button type="button" wire:click="setTab('points')"
                @class(['px-4 py-2 text-sm font-medium border-b-2 -mb-px', 'border-slate-900 text-slate-900' => $activeTab === 'points', 'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'points'])>
            Loyalty Points
        </button>
        <button type="button" wire:click="setTab('referrals')"
                @class(['px-4 py-2 text-sm font-medium border-b-2 -mb-px', 'border-slate-900 text-slate-900' => $activeTab === 'referrals', 'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'referrals'])>
            Referrals
        </button>
    </div>

    @if ($activeTab === 'points')
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="text-xs text-gray-500 mb-1">Total earned (filtered)</div>
                <div class="text-xl font-semibold text-green-700 font-mono">{{ number_format($totalPointsEarned) }} pts</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="text-xs text-gray-500 mb-1">Total redeemed (filtered)</div>
                <div class="text-xl font-semibold text-red-700 font-mono">{{ number_format($totalPointsRedeemed) }} pts</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="text-xs text-gray-500 mb-1">Net (filtered)</div>
                <div class="text-xl font-semibold font-mono">{{ number_format($totalPointsEarned - $totalPointsRedeemed) }} pts</div>
            </div>
        </div>

        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search customer name or phone..." class="border rounded px-3 py-2 text-sm w-72 mb-4">

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Customer</th>
                        <th class="px-4 py-2">Points</th>
                        <th class="px-4 py-2">Reason</th>
                        <th class="px-4 py-2">Booking</th>
                        <th class="px-4 py-2">Expires</th>
                        <th class="px-4 py-2">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($points as $p)
                        <tr class="border-t hover:bg-gray-50">
                            <td class="px-4 py-2">{{ $p->user->name ?? '—' }} <span class="text-gray-400">({{ $p->user->phone ?? '—' }})</span></td>
                            <td class="px-4 py-2 font-mono @if($p->points > 0) text-green-700 @else text-red-700 @endif">
                                {{ $p->points > 0 ? '+' : '' }}{{ number_format($p->points) }}
                            </td>
                            <td class="px-4 py-2 text-gray-500">{{ str_replace('_', ' ', $p->reason) }}</td>
                            <td class="px-4 py-2 text-gray-400">{{ $p->booking->code ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-400">{{ $p->expires_at ? app(\App\Services\TimezoneResolver::class)->format($p->expires_at, $p->user?->franchise, 'd M Y') : 'Never' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ app(\App\Services\TimezoneResolver::class)->format($p->created_at, $p->user?->franchise, 'd M Y, h:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No loyalty point entries match your search.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t">{{ $points->links() }}</div>
        </div>
    @else
        <div class="flex flex-wrap gap-3 mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search referrer or referred name/phone..." class="border rounded px-3 py-2 text-sm w-72">
            <select wire:model.live="referralStatusFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="rewarded">Rewarded</option>
                <option value="expired">Expired</option>
                <option value="fraud_flagged">Fraud flagged</option>
            </select>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Referrer</th>
                        <th class="px-4 py-2">Referred</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Reward</th>
                        <th class="px-4 py-2">Qualifying booking</th>
                        <th class="px-4 py-2">Date</th>
                        @if ($canManageAnywhere ?? false)
                            <th class="px-4 py-2">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($referrals as $r)
                        <tr class="border-t hover:bg-gray-50 align-top">
                            <td class="px-4 py-2">{{ $r->referrer->name ?? '—' }} <span class="text-gray-400">({{ $r->referrer->phone ?? '—' }})</span></td>
                            <td class="px-4 py-2">{{ $r->referred->name ?? '—' }} <span class="text-gray-400">({{ $r->referred->phone ?? '—' }})</span></td>
                            <td class="px-4 py-2">
                                <span @class(['px-2 py-0.5 rounded text-xs', 'bg-amber-100 text-amber-700' => $r->status === 'pending', 'bg-green-100 text-green-700' => $r->status === 'rewarded', 'bg-gray-100 text-gray-500' => $r->status === 'expired', 'bg-red-100 text-red-700' => $r->status === 'fraud_flagged'])>
                                    {{ str_replace('_', ' ', ucfirst($r->status)) }}
                                </span>
                                @if ($r->reversed_at)
                                    <div class="text-xs text-gray-400 mt-1">{{ $r->reversal_note }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2 font-mono text-gray-500">{{ $r->reward_amount ? $currencySymbol.number_format($r->reward_amount, 2) : '—' }}</td>
                            <td class="px-4 py-2 text-gray-400">{{ $r->qualifyingBooking->code ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ app(\App\Services\TimezoneResolver::class)->format($r->created_at, $r->referrer?->franchise, 'd M Y, h:i A') }}</td>
                            @if ($canManageAnywhere ?? false)
                                <td class="px-4 py-2">
                                    @if ($r->status !== 'fraud_flagged')
                                        <button type="button" wire:click="startFlagging({{ $r->id }})" class="text-red-600 hover:underline text-xs">Flag as fraud</button>
                                    @endif
                                    @if ($flaggingReferralId === $r->id)
                                        <div class="mt-2">
                                            <textarea wire:model="fraudNotes" rows="2" placeholder="Reason (required)" class="w-48 border rounded px-2 py-1 text-xs"></textarea>
                                            @error('fraudNotes')<p class="text-red-600 text-xs">{{ $message }}</p>@enderror
                                            <button type="button" wire:click="flagFraud" class="block mt-1 text-red-700 font-medium text-xs">Confirm flag</button>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ ($canManageAnywhere ?? false) ? 7 : 6 }}" class="px-4 py-6 text-center text-gray-400">No referrals match your search.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t">{{ $referrals->links() }}</div>
        </div>
    @endif
</div>
