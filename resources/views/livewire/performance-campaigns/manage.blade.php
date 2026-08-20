<div>
    <h1 class="text-2xl font-bold mb-4">Performance / Growth Campaigns</h1>
    <p class="text-xs text-gray-400 mb-4">Not notification broadcasts — real, measurable incentive campaigns paid through the wallet/loyalty/badge engines.</p>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">New campaign (draft)</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div><label class="block text-xs font-medium mb-1">Name</label><input type="text" wire:model="name" class="w-full border rounded px-3 py-2 text-sm">@error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
            <div><label class="block text-xs font-medium mb-1">Audience</label>
                <select wire:model="audienceType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="franchise">Franchise</option>
                    <option value="provider">Partner (Provider)</option>
                    <option value="field_worker">Rider / Worker</option>
                    <option value="customer">Customer</option>
                </select>
            </div>
            <div><label class="block text-xs font-medium mb-1">Metric</label>
                <select wire:model="metricKey" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach ($metrics as $m)<option value="{{ $m }}">{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach
                </select>
            </div>
            <div><label class="block text-xs font-medium mb-1">Qualification</label>
                <select wire:model.live="qualificationMode" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="threshold">Threshold (metric ≥ target)</option>
                    <option value="top_n">Top N performers</option>
                </select>
            </div>
            @if ($qualificationMode === 'threshold')
                <div><label class="block text-xs font-medium mb-1">Target value</label><input type="number" step="0.01" wire:model="targetValue" class="w-full border rounded px-3 py-2 text-sm">@error('targetValue')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
            @else
                <div><label class="block text-xs font-medium mb-1">Top N</label><input type="number" wire:model="topN" class="w-full border rounded px-3 py-2 text-sm">@error('topN')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
            @endif
            <div><label class="block text-xs font-medium mb-1">Reward type</label>
                <select wire:model.live="rewardType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="wallet_credit">Wallet credit</option>
                    <option value="loyalty_points">Loyalty points</option>
                    <option value="badge">Badge</option>
                </select>
            </div>
            @if ($rewardType === 'badge')
                <div><label class="block text-xs font-medium mb-1">Reward badge</label>
                    <select wire:model="badgeId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Select…</option>
                        @foreach ($badges as $b)<option value="{{ $b->id }}">{{ $b->label }}</option>@endforeach
                    </select>
                    @error('badgeId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            @else
                <div><label class="block text-xs font-medium mb-1">Reward value</label><input type="number" step="0.01" wire:model="rewardValue" class="w-full border rounded px-3 py-2 text-sm">@error('rewardValue')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
            @endif
            <div><label class="block text-xs font-medium mb-1">Starts at</label><input type="datetime-local" wire:model="startsAt" class="w-full border rounded px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-medium mb-1">Ends at</label><input type="datetime-local" wire:model="endsAt" class="w-full border rounded px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-medium mb-1">Scope</label>
                <select wire:model.live="scopeType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="global">Global</option><option value="country">Country</option><option value="city">City</option><option value="franchise">Franchise</option><option value="zone">Zone</option>
                </select>
            </div>
        </div>
        <div class="mt-3"><label class="block text-xs font-medium mb-1">Description</label><textarea wire:model="description" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea></div>
        @if ($scopeType === 'franchise')
            <div class="mt-3"><select wire:model="scopeFranchiseId" class="border rounded px-3 py-2 text-sm"><option value="">Select franchise…</option>@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select></div>
        @elseif ($scopeType === 'zone')
            <div class="mt-3"><select wire:model="scopeFranchiseId" class="border rounded px-3 py-2 text-sm mr-2"><option value="">Franchise…</option>@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
            <select wire:model="scopeZoneId" class="border rounded px-3 py-2 text-sm"><option value="">Zone…</option>@foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach</select></div>
        @endif
        <div class="flex justify-end pt-4 mt-4 border-t">
            <x-ui.button size="lg" wire:click="create">Create Draft</x-ui.button>
        </div>
    </x-ui.card>

    <div class="space-y-3">
        @foreach ($campaigns as $campaign)
            <x-ui.card>
                <div class="flex items-center justify-between">
                    <div>
                        <span class="font-semibold">{{ $campaign->name }}</span>
                        <span class="text-gray-400 text-xs ml-2">{{ ucfirst($campaign->audience_type) }} · {{ ucfirst(str_replace('_', ' ', $campaign->metric_key)) }}</span>
                        <x-ui.badge class="ml-2" :color="match($campaign->status) {
                            'draft' => 'gray',
                            'scheduled' => 'blue',
                            'active' => 'green',
                            'paused', 'under_review' => 'amber',
                            'approved' => 'purple',
                            'rewarded' => 'emerald',
                            'completed', 'closed' => 'slate',
                            'cancelled' => 'red',
                            default => 'gray',
                        }">{{ ucfirst(str_replace('_', ' ', $campaign->status)) }}</x-ui.badge>
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ $campaign->qualification_mode === 'threshold' ? 'Target ≥ '.number_format($campaign->target_value, 2) : 'Top '.$campaign->top_n }}
                        · Reward: {{ $campaign->reward_type === 'badge' ? ($campaign->badge->label ?? 'Badge') : number_format($campaign->reward_value, 2).' '.($campaign->reward_type === 'wallet_credit' ? $currencySymbol : 'pts') }}
                    </div>
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    {{ $campaign->starts_at?->format('d M Y, h:i A') ?? 'No start set' }} → {{ $campaign->ends_at?->format('d M Y, h:i A') ?? '—' }}
                    · Scope: {{ ucfirst($campaign->scope_type) }}@if($campaign->scope_id) #{{ $campaign->scope_id }}@endif
                    · Participants: {{ $campaign->participants_count }}
                </div>

                <div class="flex gap-2 mt-3 flex-wrap">
                    @if ($campaign->status === 'draft')
                        <x-ui.button variant="ghost" wire:click="lifecycleAction({{ $campaign->id }}, 'schedule')">Schedule</x-ui.button>
                    @endif
                    @if ($campaign->status === 'scheduled')
                        <x-ui.button variant="ghost" color="green" wire:click="lifecycleAction({{ $campaign->id }}, 'activate')">Activate</x-ui.button>
                    @endif
                    @if (in_array($campaign->status, ['active', 'paused']))
                        <x-ui.button variant="ghost" color="slate" wire:click="lifecycleAction({{ $campaign->id }}, 'refreshProgress')">Refresh Progress</x-ui.button>
                    @endif
                    @if ($campaign->status === 'active')
                        <x-ui.button variant="ghost" color="amber" wire:click="lifecycleAction({{ $campaign->id }}, 'pause')">Pause</x-ui.button>
                        <x-ui.button variant="ghost" color="slate" wire:click="lifecycleAction({{ $campaign->id }}, 'complete')" wire:confirm="End this campaign now?">End Now</x-ui.button>
                    @endif
                    @if ($campaign->status === 'paused')
                        <x-ui.button variant="ghost" wire:click="lifecycleAction({{ $campaign->id }}, 'resume')">Resume</x-ui.button>
                    @endif
                    @if ($campaign->status === 'completed')
                        <x-ui.button variant="ghost" color="purple" wire:click="lifecycleAction({{ $campaign->id }}, 'submitForReview')">Submit for Review</x-ui.button>
                    @endif
                    @if ($campaign->status === 'under_review')
                        <x-ui.button variant="ghost" color="purple" wire:click="approve({{ $campaign->id }})" wire:confirm="Approve this campaign for reward payout?">Approve</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="lifecycleAction({{ $campaign->id }}, 'reopen')">Reopen</x-ui.button>
                    @endif
                    @if ($campaign->status === 'approved')
                        <x-ui.button variant="ghost" class="!text-emerald-700 !font-semibold" wire:click="disburse({{ $campaign->id }})" wire:confirm="Disburse rewards to every qualified participant now? This moves real money/points/badges.">Disburse Rewards</x-ui.button>
                    @endif
                    @if (! in_array($campaign->status, ['rewarded', 'closed', 'cancelled']))
                        <x-ui.button variant="ghost" color="red" wire:click="lifecycleAction({{ $campaign->id }}, 'cancel')" wire:confirm="Cancel this campaign?">Cancel</x-ui.button>
                    @endif
                    @if ($campaign->status === 'rewarded')
                        <x-ui.button variant="ghost" color="slate" wire:click="lifecycleAction({{ $campaign->id }}, 'close')">Close</x-ui.button>
                    @endif
                    <x-ui.button variant="ghost" color="gray" wire:click="toggleParticipants({{ $campaign->id }})">{{ $expandedCampaignId === $campaign->id ? 'Hide participants' : 'View participants' }}</x-ui.button>
                </div>

                @if ($expandedCampaignId === $campaign->id)
                    <div class="mt-3 pt-3 border-t overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-left text-gray-500"><tr><th class="pr-4 py-1">Rank</th><th class="pr-4 py-1">Participant</th><th class="pr-4 py-1">Metric value</th><th class="pr-4 py-1">Qualified</th><th class="pr-4 py-1">Reward status</th></tr></thead>
                            <tbody>
                                @forelse ($this->expandedParticipants as $p)
                                    <tr class="border-t">
                                        <td class="pr-4 py-1">{{ $p->rank ?? '—' }}</td>
                                        <td class="pr-4 py-1">{{ $p->actor_label }}</td>
                                        <td class="pr-4 py-1 font-mono">{{ number_format($p->metric_value, 2) }}</td>
                                        <td class="pr-4 py-1">{{ $p->qualified ? 'Yes' : 'No' }}</td>
                                        <td class="pr-4 py-1">{{ ucfirst(str_replace('_', ' ', $p->reward_status)) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="py-3 text-center text-gray-400">No participants yet — click "Refresh Progress" first.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    </div>
    <div class="mt-4">{{ $campaigns->links() }}</div>
</div>
