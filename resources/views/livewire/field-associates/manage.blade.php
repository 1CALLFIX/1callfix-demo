{{-- Admin → Field associates (owner 2026-11-23): add associates, their month at a glance, each one's shops with progress
     bars, and the month's sheet to pay from. --}}
@php($money = fn (float $a) => \App\Services\FieldAssociates\FieldAssociateTracker::money($a))
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">🤝 Field associates</h1>
            <p class="text-sm text-gray-500">People who visit shops and restaurants and sign them up. Each shop signed up with their link or QR is added to them.</p>
        </div>
        <x-ui.button variant="secondary" size="sm" wire:click="exportCsv" data-testid="fa-export">⬇ Download {{ $month->format('F') }} sheet</x-ui.button>
    </div>

    @if ($flash)
        <div role="status" class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="fa-flash">{{ $flash }}</div>
    @endif

    {{-- 1. Rewards (Super Admin) --}}
    @if ($canSetRewards)
        <section class="mb-5 rounded-2xl border-2 border-emerald-200 bg-emerald-50/60 p-4" data-testid="fa-rewards">
            <h2 class="text-base font-bold text-emerald-900">💰 Rewards</h2>
            @unless ($rewardsSet)
                <p class="mt-1 text-sm font-medium text-amber-800">Set the reward amounts before associates start. A reward is saved with the amount at the time it is earned.</p>
            @endunless
            <form wire:submit="saveRewards" class="mt-3 grid gap-3 sm:grid-cols-4 sm:items-end">
                <label class="block text-sm"><span class="font-medium text-gray-800">✅ When a shop goes live (KYC + shop approved)</span>
                    <span class="mt-1 flex items-center rounded-lg border bg-white px-3"><span class="text-gray-500">₹</span><input type="number" min="0" step="1" wire:model="rewardShopLive" class="min-h-11 w-full border-0 px-2 focus:ring-0" data-testid="fa-reward-live"></span>
                    @error('rewardShopLive') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="font-medium text-gray-800">🎉 On the shop's first completed order</span>
                    <span class="mt-1 flex items-center rounded-lg border bg-white px-3"><span class="text-gray-500">₹</span><input type="number" min="0" step="1" wire:model="rewardFirstOrder" class="min-h-11 w-full border-0 px-2 focus:ring-0" data-testid="fa-reward-first"></span>
                    @error('rewardFirstOrder') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="font-medium text-gray-800">🎯 Monthly target (shops live, 0 = none)</span>
                    <input type="number" min="0" step="1" wire:model="monthlyTarget" class="mt-1 min-h-11 w-full rounded-lg border px-3" data-testid="fa-target">
                    @error('monthlyTarget') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <button type="submit" class="min-h-11 rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700">Save rewards</button>
            </form>
            <p class="mt-2 text-xs text-emerald-900/80">The first order counts only when a real customer completes it, never the shop owner or the associate. Rewards are paid outside the app from the monthly sheet.</p>
        </section>
    @endif

    {{-- 2. Add an associate --}}
    <section class="mb-5 rounded-2xl border border-blue-200 bg-blue-50/60 p-4">
        <h2 class="text-base font-bold text-blue-900">➕ Add an associate</h2>
        <form wire:submit="addAssociate" class="mt-3 grid gap-3 sm:grid-cols-4 sm:items-start">
            <label class="block text-sm"><span class="font-medium text-gray-800">Name</span>
                <input type="text" wire:model="name" class="mt-1 min-h-11 w-full rounded-lg border px-3" data-testid="fa-name">
                @error('name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block text-sm"><span class="font-medium text-gray-800">Mobile number</span>
                <input type="tel" inputmode="numeric" wire:model="phone" class="mt-1 min-h-11 w-full rounded-lg border px-3" data-testid="fa-phone">
                @error('phone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block text-sm"><span class="font-medium text-gray-800">City team</span>
                <select wire:model="franchiseId" class="mt-1 min-h-11 w-full rounded-lg border px-3" data-testid="fa-franchise">
                    <option value="">Choose…</option>
                    @foreach ($franchiseOptions as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
                @error('franchiseId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <button type="submit" class="mt-6 min-h-11 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="fa-add">Add associate</button>
        </form>
    </section>

    {{-- 3. The month --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        @foreach ([0 => 'This month', 1 => 'Last month', 2 => now()->startOfMonth()->subMonthsNoOverflow(2)->format('F')] as $ago => $label)
            <button type="button" wire:click="showMonth({{ $ago }})" @class(['min-h-10 rounded-full px-4 text-sm font-semibold ring-1',
                'bg-slate-900 text-white ring-slate-900' => $monthsAgo === $ago, 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' => $monthsAgo !== $ago])>{{ $label }}</button>
        @endforeach
        <span class="text-sm text-gray-500">{{ $month->format('F Y') }}</span>
    </div>

    @if ($associates->isEmpty())
        <x-ui.card><p class="text-sm text-gray-600" data-testid="fa-empty">No field associates yet. Add the first one above.</p></x-ui.card>
    @else
        <ul class="space-y-3">
            @foreach ($associates as $a)
                @php($t = $totals[$a->id])
                @php($pct = $target > 0 ? min(100, (int) round($t['live'] / $target * 100)) : null)
                <li class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200" wire:key="fa-{{ $a->id }}" data-testid="fa-row-{{ $a->code }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-lg bg-slate-900 px-2 py-1 font-mono text-xs font-bold text-white">{{ $a->code }}</span>
                        <div class="min-w-[10rem] flex-1">
                            <p class="font-semibold text-gray-900">{{ $a->user?->name }} @unless ($a->is_active)<span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Paused</span>@endunless</p>
                            <p class="text-sm text-gray-500">{{ $a->user?->phone }}@if ($a->franchise) · {{ $a->franchise->name }}@endif</p>
                        </div>
                        <dl class="flex flex-wrap gap-2 text-center text-sm">
                            <div class="rounded-xl bg-slate-50 px-3 py-1.5"><dt class="text-xs text-gray-500">Signed up</dt><dd class="font-bold">{{ $t['signed_up'] }}</dd></div>
                            <div class="rounded-xl bg-emerald-50 px-3 py-1.5"><dt class="text-xs text-emerald-700">Live ✅</dt><dd class="font-bold text-emerald-800" data-testid="fa-row-live">{{ $t['live'] }}</dd></div>
                            <div class="rounded-xl bg-violet-50 px-3 py-1.5"><dt class="text-xs text-violet-700">First orders 🎉</dt><dd class="font-bold text-violet-800">{{ $t['first_orders'] }}</dd></div>
                            <div class="rounded-xl bg-emerald-600 px-3 py-1.5 text-white"><dt class="text-xs text-white/80">To pay</dt><dd class="font-bold" data-testid="fa-row-earned">{{ $money($t['earned']) }}</dd></div>
                        </dl>
                    </div>
                    @if ($pct !== null)
                        <div class="mt-3 flex items-center gap-2">
                            <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}" aria-label="{{ $a->code }} target {{ $pct }}%">
                                <div class="h-full rounded-full {{ $pct >= 100 ? 'bg-emerald-500' : 'bg-blue-500' }}" style="width: {{ max(2, $pct) }}%"></div>
                            </div>
                            <span class="text-xs font-semibold text-gray-600">{{ $t['live'] }} / {{ $target }}</span>
                        </div>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="open({{ $a->id }})" class="min-h-10 rounded-xl bg-blue-50 px-4 text-sm font-semibold text-blue-700 hover:bg-blue-100" data-testid="fa-open-{{ $a->code }}">{{ $open?->id === $a->id ? 'Hide shops' : 'See shops' }}</button>
                        <button type="button" wire:click="toggleActive({{ $a->id }})" wire:confirm="{{ $a->is_active ? 'Pause '.$a->code.'? Their link stops adding shops to them.' : 'Resume '.$a->code.'?' }}" class="min-h-10 rounded-xl bg-slate-100 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-200" data-testid="fa-toggle-{{ $a->code }}">{{ $a->is_active ? 'Pause' : 'Resume' }}</button>
                    </div>

                    @if ($open?->id === $a->id)
                        <div class="mt-4 border-t pt-4">
                            @if ($assignable->isNotEmpty())
                                <form wire:submit="assignStore" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl bg-slate-50 p-3">
                                    <label class="block flex-1 text-sm"><span class="font-medium text-gray-800">Forgot the link? Add a shop they signed up (last 60 days)</span>
                                        <select wire:model="assignStoreId" class="mt-1 min-h-11 w-full rounded-lg border px-3" data-testid="fa-assign-select">
                                            <option value="">Choose a shop…</option>
                                            @foreach ($assignable as $s)
                                                <option value="{{ $s->id }}">{{ $s->module === 'food' ? '🍽️' : '🏪' }} {{ $s->name }} · {{ $s->created_at?->format('j M') }}</option>
                                            @endforeach
                                        </select>
                                        @error('assignStoreId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <button type="submit" class="min-h-11 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white" data-testid="fa-assign">Add to {{ $a->code }}</button>
                                </form>
                            @endif
                            @if ($openShops->isEmpty())
                                <p class="text-sm text-gray-500">No shops yet.</p>
                            @else
                                <ul class="grid gap-3 lg:grid-cols-2">
                                    @foreach ($openShops as $row)
                                        <x-field-associate.shop-progress :row="$row" wire:key="fa-open-shop-{{ $row['store']->id }}">
                                            <button type="button" wire:click="removeStore({{ $row['store']->id }})" wire:confirm="Remove {{ $row['store']->name }} from {{ $a->code }}? Its rewards are removed too." class="mt-2 text-xs font-medium text-red-600 underline underline-offset-2">Not theirs? Remove</button>
                                        </x-field-associate.shop-progress>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
