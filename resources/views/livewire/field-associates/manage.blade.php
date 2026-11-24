{{-- Admin → Field associates (owner 2026-11-23, release A 2026-11-24): rewards, add associates, their month at a glance
     (shops and restaurants apart), each one's shops with progress bars and credit history, and the month's sheet. --}}
@php($money = fn (float $a) => \App\Services\FieldAssociates\FieldAssociateTracker::money($a))
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">🤝 Field associates</h1>
            <p class="text-sm text-gray-500">People who visit shops and restaurants and sign them up. Each shop or restaurant signed up with their link, QR or on their phone is added to them.</p>
        </div>
        <x-ui.button variant="secondary" size="sm" wire:click="exportCsv" data-testid="fa-export">⬇ Download {{ $month->format('F') }} sheet</x-ui.button>
    </div>

    @if ($flash)
        <div role="status" class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="fa-flash">{{ $flash }}</div>
    @endif

    {{-- 1. Rewards (Super Admin) --}}
    @if ($canSetRewards)
        <section class="mb-5 rounded-2xl border-2 border-emerald-200 bg-emerald-50/60 p-4" data-testid="fa-rewards">
            <h2 class="text-base font-bold text-emerald-900">💰 Rewards (same for shops and restaurants)</h2>
            @unless ($rewardsSet)
                <p class="mt-1 text-sm font-medium text-amber-800">Set the reward amounts before associates start. A reward is saved with the amount at the time it is earned.</p>
            @endunless
            <form wire:submit="saveRewards" class="mt-3 space-y-4">
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['rewardShopLive', '✅ Live (KYC + shop approved)', 'fa-reward-live'],
                        ['rewardProducts', '🛍️ 10 products / menu items approved', 'fa-reward-products'],
                        ['rewardFirstOrder', '🎉 First genuine order (completed + paid)', 'fa-reward-first'],
                    ] as [$field, $label, $testid])
                        <label class="block text-sm"><span class="font-medium text-gray-800">{{ $label }}</span>
                            <span class="mt-1 flex items-center rounded-lg border bg-white px-3"><span class="text-gray-500">₹</span><input type="number" min="0" step="1" wire:model="{{ $field }}" class="min-h-11 w-full border-0 px-2 focus:ring-0" data-testid="{{ $testid }}"></span>
                            @error($field) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm"><span class="font-medium text-gray-800">🧢 At most per shop or restaurant (0 = no limit)</span>
                        <span class="mt-1 flex items-center rounded-lg border bg-white px-3"><span class="text-gray-500">₹</span><input type="number" min="0" step="1" wire:model="maxPerShop" class="min-h-11 w-full border-0 px-2 focus:ring-0" data-testid="fa-max"></span>
                        @error('maxPerShop') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm"><span class="font-medium text-gray-800">🧾 First order counts from an order of (0 = any)</span>
                        <span class="mt-1 flex items-center rounded-lg border bg-white px-3"><span class="text-gray-500">₹</span><input type="number" min="0" step="1" wire:model="firstOrderMin" class="min-h-11 w-full border-0 px-2 focus:ring-0" data-testid="fa-min-order"></span>
                        @error('firstOrderMin') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
                <fieldset class="rounded-xl bg-white/70 p-3 ring-1 ring-emerald-200">
                    <legend class="px-1 text-sm font-semibold text-emerald-900">🏆 Monthly bonus (highest level reached, not added together)</legend>
                    <p class="text-xs text-gray-600">Counts shops and restaurants that became live with 10 approved products in that month. Leave a row empty to skip it.</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($bonusLevels as $i => $level)
                            <div class="flex items-center gap-2 rounded-lg bg-amber-50 p-2 text-sm ring-1 ring-amber-200" wire:key="fa-level-{{ $i }}">
                                <input type="number" min="1" step="1" wire:model="bonusLevels.{{ $i }}.shops" class="min-h-11 w-20 rounded-lg border px-2" aria-label="Level {{ $i + 1 }} shops" data-testid="fa-level-shops-{{ $i }}">
                                <span class="text-gray-700">shops →</span>
                                <span class="flex flex-1 items-center rounded-lg border bg-white px-2"><span class="text-gray-500">₹</span><input type="number" min="0" step="1" wire:model="bonusLevels.{{ $i }}.amount" class="min-h-11 w-full border-0 px-1 focus:ring-0" aria-label="Level {{ $i + 1 }} bonus" data-testid="fa-level-amount-{{ $i }}"></span>
                            </div>
                            @error("bonusLevels.$i.shops") <p class="text-xs text-red-600 sm:col-span-2">{{ $message }}</p> @enderror
                            @error("bonusLevels.$i.amount") <p class="text-xs text-red-600 sm:col-span-2">{{ $message }}</p> @enderror
                        @endforeach
                    </div>
                </fieldset>
                <button type="submit" class="min-h-11 w-full rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700 sm:w-auto">Save rewards</button>
            </form>
            <p class="mt-2 text-xs text-emerald-900/80">A first order counts only when a real customer completes and pays it, never the shop owner, the associate or a test login, and one customer counts for only one of an associate's shops. It is added {{ $holdDays }} days after the order (after the return window); a refunded order cancels it. Rewards are paid outside the app: the sheet shows what was earned, not what is approved or paid.</p>
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
                @php($goal = \App\Services\FieldAssociates\FieldAssociateTracker::goal($t['complete']))
                <li class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200" wire:key="fa-{{ $a->id }}" data-testid="fa-row-{{ $a->code }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-lg bg-slate-900 px-2 py-1 font-mono text-xs font-bold text-white">{{ $a->code }}</span>
                        <div class="min-w-[10rem] flex-1">
                            <p class="font-semibold text-gray-900">{{ $a->user?->name }}
                                @unless ($a->is_active)<span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Paused</span>@endunless
                                @if ($a->isTest())<span class="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-700" title="Test login: never on the pay sheet">Test</span>@endif
                            </p>
                            <p class="text-sm text-gray-500">{{ $a->user?->phone }}@if ($a->franchise) · {{ $a->franchise->name }}@endif</p>
                        </div>
                        <dl class="flex flex-wrap gap-2 text-center text-sm">
                            <div class="rounded-xl bg-slate-50 px-3 py-1.5"><dt class="text-xs text-gray-500">Signed up</dt><dd class="font-bold">{{ $t['signed_up'] }} <span class="text-xs font-medium text-gray-500">🏪{{ $t['signed_up_shops'] }} 🍽️{{ $t['signed_up_restaurants'] }}</span></dd></div>
                            <div class="rounded-xl bg-emerald-50 px-3 py-1.5"><dt class="text-xs text-emerald-700">Live ✅</dt><dd class="font-bold text-emerald-800" data-testid="fa-row-live">{{ $t['live'] }} <span class="text-xs font-medium">🏪{{ $t['live_shops'] }} 🍽️{{ $t['live_restaurants'] }}</span></dd></div>
                            <div class="rounded-xl bg-blue-50 px-3 py-1.5"><dt class="text-xs text-blue-700">10 products 🛍️</dt><dd class="font-bold text-blue-800">{{ $t['products'] }}</dd></div>
                            <div class="rounded-xl bg-violet-50 px-3 py-1.5"><dt class="text-xs text-violet-700">First orders 🎉</dt><dd class="font-bold text-violet-800">{{ $t['first_orders'] }}</dd></div>
                            <div class="rounded-xl bg-amber-50 px-3 py-1.5"><dt class="text-xs text-amber-700">Bonus 🏆</dt><dd class="font-bold text-amber-800" data-testid="fa-row-bonus">{{ $money($t['bonus']) }}</dd></div>
                            <div class="rounded-xl bg-emerald-600 px-3 py-1.5 text-white"><dt class="text-xs text-white/80">Earned</dt><dd class="font-bold" data-testid="fa-row-earned">{{ $money($t['earned']) }}</dd></div>
                        </dl>
                    </div>
                    @if ($goal)
                        <div class="mt-3 flex items-center gap-2">
                            <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $goal['percent'] }}" aria-label="{{ $a->code }}: {{ $goal['text'] }}">
                                <div class="h-full rounded-full {{ $goal['percent'] >= 100 ? 'bg-emerald-500' : 'bg-blue-500' }}" style="width: {{ max(2, $goal['percent']) }}%"></div>
                            </div>
                            <span class="text-xs font-semibold text-gray-600">{{ $goal['text'] }}</span>
                        </div>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="open({{ $a->id }})" class="min-h-10 rounded-xl bg-blue-50 px-4 text-sm font-semibold text-blue-700 hover:bg-blue-100" data-testid="fa-open-{{ $a->code }}">{{ $open?->id === $a->id ? 'Hide shops' : 'See shops' }}</button>
                        <button type="button" wire:click="toggleActive({{ $a->id }})" wire:confirm="{{ $a->is_active ? 'Pause '.$a->code.'? Their link stops adding shops to them.' : 'Resume '.$a->code.'?' }}" class="min-h-10 rounded-xl bg-slate-100 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-200" data-testid="fa-toggle-{{ $a->code }}">{{ $a->is_active ? 'Pause' : 'Resume' }}</button>
                    </div>

                    @if ($open?->id === $a->id)
                        <div class="mt-4 border-t pt-4">
                            @if ($assignable->isNotEmpty())
                                <form wire:submit="assignStore" class="mb-4 grid gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                    <label class="block text-sm"><span class="font-medium text-gray-800">Forgot the link? Add a shop they signed up (last 60 days)</span>
                                        <select wire:model="assignStoreId" class="mt-1 min-h-11 w-full rounded-lg border px-3" data-testid="fa-assign-select">
                                            <option value="">Choose a shop…</option>
                                            @foreach ($assignable as $s)
                                                <option value="{{ $s->id }}">{{ $s->module === 'food' ? '🍽️' : '🏪' }} {{ $s->name }} · {{ $s->created_at?->format('j M') }}</option>
                                            @endforeach
                                        </select>
                                        @error('assignStoreId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block text-sm"><span class="font-medium text-gray-800">Why?</span>
                                        <input type="text" wire:model="assignReason" placeholder="e.g. Owner says Ravi visited on 12 Dec" class="mt-1 min-h-11 w-full rounded-lg border px-3" data-testid="fa-assign-reason">
                                        @error('assignReason') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <button type="submit" class="min-h-11 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white" data-testid="fa-assign">Add to {{ $a->code }}</button>
                                </form>
                            @endif
                            @if ($openShops->isEmpty())
                                <p class="text-sm text-gray-500">No shops yet.</p>
                            @else
                                <ul class="grid gap-3 lg:grid-cols-2">
                                    @foreach ($openShops as $row)
                                        @php($sid = $row['store']->id)
                                        <x-field-associate.shop-progress :row="$row" wire:key="fa-open-shop-{{ $sid }}">
                                            @if ($row['history']->isNotEmpty())
                                                <details class="mt-2 text-xs text-slate-600">
                                                    <summary class="cursor-pointer font-medium text-slate-700">Credit history ({{ $row['history']->count() }})</summary>
                                                    <ul class="mt-1 space-y-1" data-testid="fa-history">
                                                        @foreach ($row['history'] as $h)
                                                            <li>{{ $h->created_at?->format('j M Y, g:i a') }}: {{ $h->from?->code ?? '—' }} → {{ $h->to?->code ?? 'nobody' }} · {{ $h->changedBy?->name ?? 'system' }} · “{{ $h->reason }}”</li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            @endif
                                            @if ($changeStoreId === $sid)
                                                <form wire:submit="saveChange" class="mt-3 space-y-2 rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200" data-testid="fa-change-form">
                                                    <div class="flex flex-wrap gap-2 text-sm">
                                                        <label class="flex min-h-10 items-center gap-2 rounded-lg bg-white px-3 ring-1 ring-slate-200"><input type="radio" value="move" wire:model.live="changeAction"> Move to another associate</label>
                                                        <label class="flex min-h-10 items-center gap-2 rounded-lg bg-white px-3 ring-1 ring-slate-200"><input type="radio" value="remove" wire:model.live="changeAction"> Remove (nobody signed it up)</label>
                                                    </div>
                                                    @if ($changeAction === 'move')
                                                        <select wire:model="moveToId" class="min-h-11 w-full rounded-lg border px-3" data-testid="fa-move-to">
                                                            <option value="">Who really signed it up?</option>
                                                            @foreach ($moveTargets as $m)
                                                                <option value="{{ $m->id }}">{{ $m->code }} · {{ $m->user?->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        @error('moveToId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                                    @endif
                                                    <input type="text" wire:model="changeReason" placeholder="Why? (kept in the history)" class="min-h-11 w-full rounded-lg border px-3" data-testid="fa-change-reason">
                                                    @error('changeReason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                                    <p class="text-xs text-amber-900">{{ $changeAction === 'move' ? 'Its rewards move with it.' : 'Its rewards are removed.' }}</p>
                                                    <div class="flex gap-2">
                                                        <button type="submit" class="min-h-11 flex-1 rounded-xl bg-amber-600 px-4 text-sm font-semibold text-white hover:bg-amber-700" data-testid="fa-change-save">Save</button>
                                                        <button type="button" wire:click="cancelChange" class="min-h-11 rounded-xl bg-white px-4 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">Cancel</button>
                                                    </div>
                                                </form>
                                            @else
                                                <button type="button" wire:click="startChange({{ $sid }})" class="mt-2 min-h-10 text-xs font-medium text-amber-700 underline underline-offset-2" data-testid="fa-change-{{ $sid }}">Not theirs? Move or remove</button>
                                            @endif
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
