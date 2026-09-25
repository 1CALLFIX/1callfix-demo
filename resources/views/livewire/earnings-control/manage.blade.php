{{-- REF 1CF-PROMPT-20260925-EARN3 — Stage 3. Super Admin only (every action
     re-checks server-side). Switches / freeze / adjustments / ledger /
     monitoring for wallet, loyalty and referral. --}}
<div>
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-2xl font-bold">Earnings Control</h1>
        <p class="text-xs text-gray-500">Super Admin only · every change is audited · unset = OFF</p>
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="mb-4 flex flex-wrap gap-1 border-b">
        @foreach (['switches' => 'Switches & limits', 'freeze' => 'Freeze', 'adjust' => 'Adjustments', 'ledger' => 'Ledger viewer', 'monitoring' => 'Monitoring'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    @class(['px-4 py-2 text-sm font-medium border-b-2 -mb-px', 'border-slate-900 text-slate-900' => $tab === $key, 'border-transparent text-gray-500 hover:text-gray-700' => $tab !== $key])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ═══════════════════════ Switches ═══════════════════════ --}}
    @if ($tab === 'switches')
        <x-ui.card class="mb-4">
            <div class="flex flex-wrap items-end gap-3 text-sm">
                <label class="block">
                    <span class="block text-xs font-medium mb-1">Scope</span>
                    <select wire:model.live="scopeType" class="border rounded px-2 py-1.5">
                        <option value="global">Global</option>
                        <option value="franchise">Franchise</option>
                        <option value="zone">Zone</option>
                    </select>
                </label>
                @if ($scopeType !== 'global')
                    <label class="block">
                        <span class="block text-xs font-medium mb-1">Franchise</span>
                        <select wire:model.live="scopeFranchiseId" class="border rounded px-2 py-1.5">
                            <option value="">—</option>
                            @foreach ($franchises as $f)
                                <option value="{{ $f->id }}">{{ $f->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @if ($scopeType === 'zone')
                    <label class="block">
                        <span class="block text-xs font-medium mb-1">Zone</span>
                        <select wire:model.live="scopeZoneId" class="border rounded px-2 py-1.5">
                            <option value="">—</option>
                            @foreach ($zones as $z)
                                <option value="{{ $z->id }}">{{ $z->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <p class="text-xs text-gray-500">A scoped value overrides the global one for that franchise / zone. UNSET at a scope inherits; UNSET globally means OFF.</p>
            </div>
        </x-ui.card>

        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Switch</th>
                    <th class="px-4 py-2">Global</th>
                    @if ($scopeType !== 'global')<th class="px-4 py-2">This {{ $scopeType }}</th>@endif
                    <th class="px-4 py-2">Set {{ $scopeType === 'global' ? 'global' : 'at this '.$scopeType }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($switches as $key => $s)
                    <tr class="border-t">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ $s['label'] }}</div>
                            <div class="font-mono text-xs text-gray-400">{{ $key }}@if ($s['global_only']) · global only @endif</div>
                        </td>
                        <td class="px-4 py-2"><x-ui.badge :color="['ON' => 'green', 'OFF' => 'red'][$s['global']] ?? 'gray'">{{ $s['global'] }}</x-ui.badge></td>
                        @if ($scopeType !== 'global')
                            <td class="px-4 py-2">@if ($s['here'] !== null)<x-ui.badge :color="['ON' => 'green', 'OFF' => 'red'][$s['here']] ?? 'gray'">{{ $s['here'] }}</x-ui.badge>@else<span class="text-xs text-gray-400">global only</span>@endif</td>
                        @endif
                        <td class="px-4 py-2 whitespace-nowrap">
                            <button type="button" wire:click="setSwitch('{{ $key }}', '1')" class="rounded border px-2 py-1 text-xs hover:bg-green-50">ON</button>
                            <button type="button" wire:click="setSwitch('{{ $key }}', '0')" class="rounded border px-2 py-1 text-xs hover:bg-red-50">OFF</button>
                            <button type="button" wire:click="setSwitch('{{ $key }}', 'unset')" class="rounded border px-2 py-1 text-xs hover:bg-gray-50">UNSET</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        <x-ui.card class="mt-4">
            <h2 class="mb-3 text-sm font-semibold">Limits &amp; monitoring thresholds</h2>
            <form wire:submit="saveLimits" class="grid gap-3 sm:grid-cols-2">
                @foreach ($limits as $key => $meta)
                    <label class="block text-sm">
                        <span class="block text-xs font-medium mb-1">{{ $meta['label'] }}@if ($meta['global']) <span class="text-gray-400">(global only)</span>@endif</span>
                        <input type="text" inputmode="decimal" wire:model="limitInputs.{{ \App\Livewire\EarningsControl\Manage::field($key) }}" placeholder="UNSET (off)" class="w-full border rounded px-2 py-1.5">
                        <span class="block font-mono text-[11px] text-gray-400">{{ $key }}</span>
                        @error('limitInputs.'.\App\Livewire\EarningsControl\Manage::field($key)) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="sm:col-span-2 text-xs text-gray-500">
                    Blank = UNSET = off: adjustments disabled, referral rewards off, flag list hidden. 0 is an explicit zero.
                    Wallet top-up limits, loyalty rates and referral rewards stay on <a href="{{ route('admin.settings.index') }}" class="underline">Settings → Wallet / Loyalty</a>.
                </div>
                <div class="sm:col-span-2"><x-ui.button type="submit">Save limits</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    {{-- ═══════════════════════ User picker (freeze / adjust / ledger) ═══════════════════════ --}}
    @if (in_array($tab, ['freeze', 'adjust', 'ledger'], true))
        <x-ui.card class="mb-4">
            <label class="block text-sm">
                <span class="block text-xs font-medium mb-1">Customer or provider (name, phone or user id)</span>
                <input type="text" wire:model.live.debounce.400ms="userSearch" class="w-full max-w-md border rounded px-3 py-2" placeholder="Search…">
            </label>
            @if ($userResults->isNotEmpty())
                <ul class="mt-2 max-w-md divide-y rounded border text-sm">
                    @foreach ($userResults as $u)
                        <li><button type="button" wire:click="selectUser({{ $u->id }})" class="w-full px-3 py-2 text-left hover:bg-gray-50">#{{ $u->id }} {{ $u->name }} <span class="text-gray-400">{{ $u->phone }} · {{ $u->role }}</span></button></li>
                    @endforeach
                </ul>
            @endif

            @if ($selectedUser)
                <div class="mt-3 flex flex-wrap gap-4 text-sm">
                    <div><span class="text-gray-500">Selected:</span> <strong>#{{ $selectedUser->id }} {{ $selectedUser->name }}</strong> ({{ $selectedUser->role }})</div>
                    <div><span class="text-gray-500">Wallet:</span> <span class="font-mono">{{ $currencySymbol }}{{ number_format((float) ($selectedWallet->balance ?? 0), 2) }}</span></div>
                    <div><span class="text-gray-500">Points (live FIFO):</span> <span class="font-mono">{{ number_format($selectedPoints) }}</span></div>
                    @if ($selectedWallet?->frozen_at)
                        <x-ui.badge color="red">FROZEN since {{ $selectedWallet->frozen_at->format('j M Y H:i') }} — {{ $selectedWallet->frozen_reason }}</x-ui.badge>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- ═══════════════════════ Freeze ═══════════════════════ --}}
    @if ($tab === 'freeze' && $selectedUser)
        <x-ui.card>
            <p class="mb-2 text-xs text-gray-500">A frozen wallet refuses payments from wallet, payouts, top-ups, loyalty redemption, referral and admin credits. Refunds and earned income still credit and are flagged under Monitoring.</p>
            <label class="block text-sm">
                <span class="block text-xs font-medium mb-1">Reason (required)</span>
                <input type="text" wire:model="freezeReason" class="w-full max-w-lg border rounded px-3 py-2">
            </label>
            <div class="mt-3 flex gap-2">
                @if ($selectedWallet?->frozen_at)
                    <x-ui.button wire:click="unfreeze">Unfreeze wallet</x-ui.button>
                @else
                    <x-ui.button wire:click="freeze">Freeze wallet</x-ui.button>
                @endif
            </div>
        </x-ui.card>
    @endif

    {{-- ═══════════════════════ Adjust ═══════════════════════ --}}
    @if ($tab === 'adjust' && $selectedUser)
        <x-ui.card>
            <p class="mb-3 text-xs text-gray-500">Adjustments never edit an existing entry — each is a new compensating ledger row with your admin id and reason. Max per adjustment: wallet {{ $walletAdjustMax === null ? 'UNSET (disabled)' : $currencySymbol.number_format($walletAdjustMax, 2) }}, points {{ $pointsAdjustMax === null ? 'UNSET (disabled)' : number_format($pointsAdjustMax) }}.</p>
            <form wire:submit="adjust" class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">Ledger</span>
                    <select wire:model="adjustKind" class="w-full border rounded px-2 py-1.5"><option value="wallet">Wallet (₹)</option><option value="points">Loyalty points</option></select>
                </label>
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">Direction</span>
                    <select wire:model="adjustDirection" class="w-full border rounded px-2 py-1.5"><option value="credit">Credit</option><option value="debit">Debit</option></select>
                </label>
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">Amount</span>
                    <input type="text" inputmode="decimal" wire:model="adjustAmount" class="w-full border rounded px-2 py-1.5">
                    @error('adjustAmount') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">Reason (required)</span>
                    <input type="text" wire:model="adjustReason" class="w-full border rounded px-2 py-1.5">
                    @error('adjustReason') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <div class="sm:col-span-2"><x-ui.button type="submit">Record adjustment</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    {{-- ═══════════════════════ Ledger viewer ═══════════════════════ --}}
    @if ($tab === 'ledger' && $selectedUser)
        <div class="mb-3 flex flex-wrap items-end gap-3 text-sm">
            <label class="block"><span class="block text-xs font-medium mb-1">Ledger</span>
                <select wire:model.live="ledgerKind" class="border rounded px-2 py-1.5"><option value="wallet">Wallet</option><option value="points">Points</option></select>
            </label>
            @if ($ledgerKind === 'wallet')
                <label class="block"><span class="block text-xs font-medium mb-1">Source</span>
                    <select wire:model.live="ledgerLabel" class="border rounded px-2 py-1.5">
                        <option value="">All</option>
                        @foreach ($labelOptions as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                </label>
            @endif
            <label class="block"><span class="block text-xs font-medium mb-1">From</span><input type="date" wire:model.live="ledgerFrom" class="border rounded px-2 py-1.5"></label>
            <label class="block"><span class="block text-xs font-medium mb-1">To</span><input type="date" wire:model.live="ledgerTo" class="border rounded px-2 py-1.5"></label>
        </div>

        <x-ui.table>
            <x-slot:footer>{{ $ledger->links() }}</x-slot:footer>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Source</th><th class="px-4 py-2">Amount</th><th class="px-4 py-2">Details</th><th class="px-4 py-2">Ref</th><th class="px-4 py-2">Admin</th></tr>
            </thead>
            <tbody>
                @forelse ($ledger as $row)
                    <tr class="border-t">
                        <td class="px-4 py-2 whitespace-nowrap text-gray-500">{{ $row->created_at?->format('j M Y H:i') }}</td>
                        @if ($ledgerKind === 'wallet')
                            <td class="px-4 py-2">{{ \App\Support\WalletSourceLabel::labelFor($row->ref) }}</td>
                            <td class="px-4 py-2 font-mono {{ $row->is_credit ? 'text-green-700' : 'text-red-700' }}">{{ $row->is_credit ? '+' : '−' }}{{ $currencySymbol }}{{ number_format((float) $row->amount, 2) }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $row->reason }}</td>
                        @else
                            <td class="px-4 py-2">{{ \App\Support\WalletSourceLabel::loyaltyLabel($row) }}</td>
                            <td class="px-4 py-2 font-mono {{ $row->points >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ $row->points > 0 ? '+' : '' }}{{ number_format($row->points) }} pts</td>
                            <td class="px-4 py-2 text-gray-500">{{ $row->booking?->code }}@if ($row->expires_at) · expires {{ $row->expires_at->format('j M Y') }}@endif</td>
                        @endif
                        <td class="px-4 py-2 font-mono text-xs text-gray-400">{{ $row->ref }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $row->actor?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No entries.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif

    {{-- ═══════════════════════ Monitoring ═══════════════════════ --}}
    @if ($tab === 'monitoring')
        <div class="mb-4 flex flex-wrap items-end gap-3 text-sm">
            <label class="block"><span class="block text-xs font-medium mb-1">From</span><input type="date" wire:model.live="monFrom" class="border rounded px-2 py-1.5"></label>
            <label class="block"><span class="block text-xs font-medium mb-1">To</span><input type="date" wire:model.live="monTo" class="border rounded px-2 py-1.5"></label>
            <label class="block"><span class="block text-xs font-medium mb-1">Franchise</span>
                <select wire:model.live="monFranchiseId" class="border rounded px-2 py-1.5">
                    <option value="">All</option>
                    @foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
                </select>
            </label>
        </div>

        <h2 class="mb-2 text-sm font-semibold">Wallet totals by source</h2>
        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Source</th><th class="px-4 py-2">Entries</th><th class="px-4 py-2">Credits</th><th class="px-4 py-2">Debits</th></tr></thead>
            <tbody>
                @forelse ($totals as $t)
                    <tr class="border-t"><td class="px-4 py-2">{{ $t['label'] }}</td><td class="px-4 py-2">{{ $t['count'] }}</td><td class="px-4 py-2 font-mono text-green-700">{{ $currencySymbol }}{{ number_format($t['credit'], 2) }}</td><td class="px-4 py-2 font-mono text-red-700">{{ $currencySymbol }}{{ number_format($t['debit'], 2) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No wallet movement in this range.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>

        @php
            $flagSections = [
                ['Frozen wallets', $frozenWallets->map(fn ($w) => '#'.$w->user_id.' '.($w->user->name ?? '').' — since '.$w->frozen_at->format('j M Y').' — '.$w->frozen_reason.' (by '.($w->frozenBy->name ?? '?').')')],
                ['Money credited to frozen wallets', $frozenCredits->map(fn ($t) => ($t->wallet->user->name ?? '#'.$t->wallet_id).' — '.\App\Support\WalletSourceLabel::labelFor($t->ref).' '.$currencySymbol.number_format((float) $t->amount, 2).' on '.$t->created_at->format('j M Y'))],
                ['Admin wallet adjustments', $walletAdjustments->map(fn ($t) => ($t->wallet->user->name ?? '').' — '.($t->is_credit ? '+' : '−').$currencySymbol.number_format((float) $t->amount, 2).' by '.($t->actor->name ?? '?').' — '.$t->reason)],
                ['Admin points adjustments', $pointsAdjustments->map(fn ($p) => ($p->user->name ?? '').' — '.($p->points > 0 ? '+' : '').$p->points.' pts by '.($p->actor->name ?? '?').' — '.$p->reason)],
                ['Refunds above '.($refundThreshold === null ? 'threshold (UNSET — flag off)' : $currencySymbol.number_format($refundThreshold, 2)), $bigRefunds->map(fn ($t) => ($t->wallet->user->name ?? '').' — '.$currencySymbol.number_format((float) $t->amount, 2).' — '.$t->ref)],
                ['Referrers above '.($referralThreshold === null ? 'threshold (UNSET — flag off)' : $referralThreshold.' rewarded referrals'), $busyReferrers->map(fn ($r) => ($r->referrer->name ?? '#'.$r->referrer_id).' — '.$r->rewarded.' rewarded')],
                ['loyalty:balance-audit', collect($loyaltyAudit)->map(fn ($r) => 'user #'.$r['user_id'].' ('.$r['role'].'): old '.$r['old_balance'].' vs FIFO '.$r['fifo_balance'])],
                ['bundles:refund-audit', collect($bundleAudit)->map(fn ($r) => 'bundle #'.$r['bundle_id'].' ('.$r['bundle_code'].'): outstanding '.$currencySymbol.number_format($r['outstanding'], 2))],
            ];
        @endphp

        <div class="mt-6 grid gap-4 md:grid-cols-2">
            @foreach ($flagSections as [$title, $items])
                <x-ui.card>
                    <h3 class="mb-2 flex items-center justify-between text-sm font-semibold">{{ $title }} <x-ui.badge :color="$items->isEmpty() ? 'gray' : 'red'">{{ $items->count() }}</x-ui.badge></h3>
                    <ul class="max-h-60 space-y-1 overflow-y-auto text-xs text-gray-600">
                        @forelse ($items as $line)<li>{{ $line }}</li>@empty<li class="text-gray-400">Nothing flagged.</li>@endforelse
                    </ul>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
