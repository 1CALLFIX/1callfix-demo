{{-- Account → My field work (field associates, owner 2026-11-23, release A 2026-11-24): bonus bar + rewards, sign up a
     shop or restaurant on this phone (stays signed in) or by the owner's QR, then every shop with its progress bar.
     Private page (no Share button). --}}
@php
    $money = fn (float $a) => \App\Services\FieldAssociates\FieldAssociateTracker::money($a);
    $link = $associate->link();
    $platform = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $whatsApp = 'https://wa.me/?text='.rawurlencode("Open your shop or restaurant on {$platform}. Sign up here: {$link}");
@endphp
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">My field work</h1>
                <p class="mt-1 text-sm text-slate-600">{{ auth()->user()->name }} · Your code <span class="rounded bg-slate-900 px-1.5 py-0.5 font-mono text-xs font-bold text-white" data-testid="fa-code">{{ $associate->code }}</span></p>
            </div>
        </div>

        @if (! $associate->is_active)
            <div role="status" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="fa-paused">Your associate code is paused. Shops signed up now are not added to you. Please talk to the office.</div>
        @endif

        {{-- Month: bonus bar and rewards --}}
        <section class="mt-6 rounded-2xl bg-gradient-to-br from-blue-600 to-violet-600 p-5 text-white shadow-sm" data-testid="fa-month">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold">{{ $month->format('F Y') }}</h2>
                <div class="flex gap-1 rounded-full bg-white/15 p-1 text-sm">
                    <button type="button" wire:click="showMonth(0)" @class(['min-h-9 rounded-full px-3 font-semibold', 'bg-white text-blue-700' => $monthsAgo === 0, 'text-white' => $monthsAgo !== 0])>This month</button>
                    <button type="button" wire:click="showMonth(1)" @class(['min-h-9 rounded-full px-3 font-semibold', 'bg-white text-blue-700' => $monthsAgo === 1, 'text-white' => $monthsAgo !== 1])>Last month</button>
                </div>
            </div>

            @if ($goal)
                <p class="mt-4 text-sm text-white/90">🏆 Shops live with 10 products this month</p>
                <div class="mt-1.5 h-4 w-full overflow-hidden rounded-full bg-white/25" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $goal['percent'] }}" aria-label="{{ $goal['text'] }}">
                    <div class="h-full rounded-full bg-emerald-400" style="width: {{ max(3, $goal['percent']) }}%" data-testid="fa-target-bar"></div>
                </div>
                <p class="mt-1 text-sm font-semibold" data-testid="fa-target-text">{{ $goal['text'] }}</p>
            @endif

            <dl class="mt-4 grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
                <div class="rounded-xl bg-white/15 p-3"><dt class="text-xs text-white/80">Signed up</dt><dd class="text-2xl font-bold" data-testid="fa-signed-up">{{ $stats['signed_up'] }}</dd><dd class="text-[11px] text-white/80">🏪 {{ $stats['signed_up_shops'] }} · 🍽️ {{ $stats['signed_up_restaurants'] }}</dd></div>
                <div class="rounded-xl bg-white/15 p-3"><dt class="text-xs text-white/80">Live ✅</dt><dd class="text-2xl font-bold" data-testid="fa-live">{{ $stats['live'] }}</dd><dd class="text-[11px] text-white/80">🏪 {{ $stats['live_shops'] }} · 🍽️ {{ $stats['live_restaurants'] }}</dd></div>
                <div class="rounded-xl bg-white/15 p-3"><dt class="text-xs text-white/80">10 products 🛍️</dt><dd class="text-2xl font-bold" data-testid="fa-products">{{ $stats['products'] }}</dd></div>
                <div class="rounded-xl bg-white/15 p-3"><dt class="text-xs text-white/80">First orders 🎉</dt><dd class="text-2xl font-bold" data-testid="fa-first-orders">{{ $stats['first_orders'] }}</dd></div>
            </dl>
            <div class="mt-3 rounded-xl bg-white p-3 text-center text-slate-900">
                <p class="text-xs font-medium text-slate-500">Earned in {{ $month->format('F') }}</p>
                <p class="text-3xl font-extrabold text-emerald-600" data-testid="fa-earned">{{ $money($stats['earned']) }}</p>
                @if ($stats['bonus'] > 0)
                    <p class="text-sm font-semibold text-amber-600" data-testid="fa-bonus">incl. {{ $money($stats['bonus']) }} bonus 🏆</p>
                @endif
                <p class="mt-1 text-[11px] text-slate-500">Paid by the office after they check it.</p>
            </div>
            @if ($rewardLive > 0 || $rewardProducts > 0 || $rewardFirst > 0)
                <ul class="mt-3 grid gap-1.5 text-sm sm:grid-cols-3" data-testid="fa-reward-rules">
                    <li class="rounded-lg bg-white/15 px-3 py-2">✅ Live <span class="font-bold">{{ $money($rewardLive) }}</span></li>
                    <li class="rounded-lg bg-white/15 px-3 py-2">🛍️ 10 products <span class="font-bold">{{ $money($rewardProducts) }}</span></li>
                    <li class="rounded-lg bg-white/15 px-3 py-2">🎉 First order <span class="font-bold">{{ $money($rewardFirst) }}</span></li>
                </ul>
            @endif
            @if ($bonusLevels !== [])
                <p class="mt-2 text-sm text-white/90">🏆 Bonus: @foreach ($bonusLevels as [$levelShops, $levelAmount]){{ $levelShops }} shops {{ $money($levelAmount) }}@if (! $loop->last) · @endif @endforeach (highest reached)</p>
            @endif
        </section>

        {{-- Sign up a new shop or restaurant --}}
        @if ($associate->is_active)
            <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" data-testid="fa-add-shop">
                <h2 class="text-lg font-bold text-slate-900">➕ Add a new shop or restaurant</h2>
                <p class="mt-1 text-sm text-slate-600">Fill it in on your phone with the owner. You stay signed in; the code goes to the owner's phone.</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <a href="{{ route('customer.field-work.add', 'shop') }}" class="flex min-h-14 items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 text-base font-semibold text-white hover:bg-violet-700" data-testid="fa-assist-shop">🏪 Sign up a shop now</a>
                    <a href="{{ route('customer.field-work.add', 'restaurant') }}" class="flex min-h-14 items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 text-base font-semibold text-white hover:bg-rose-700" data-testid="fa-assist-restaurant">🍽️ Sign up a restaurant now</a>
                </div>

                <details class="mt-4 rounded-xl bg-slate-50 p-3">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800">📷 Or the owner signs up on their own phone (QR / link)</summary>
                    <ol class="mt-2 space-y-1 text-sm text-slate-700">
                        <li><span class="font-semibold text-blue-700">1.</span> The owner scans this QR with their phone camera.</li>
                        <li><span class="font-semibold text-blue-700">2.</span> They fill the form; it shows your name, so the shop is added to you.</li>
                    </ol>
                    <div class="mx-auto mt-3 aspect-square w-56 max-w-full rounded-xl bg-white p-2 ring-1 ring-slate-200" data-qr="{{ $link }}" wire:ignore aria-label="QR code to sign up a shop with your code" data-testid="fa-qr"></div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2" x-data="{ copied: false }">
                        <a href="{{ $whatsApp }}" target="_blank" rel="noopener" class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700" data-testid="fa-whatsapp">📲 Send link on WhatsApp</a>
                        <button type="button" x-on:click="navigator.clipboard?.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 2000)" class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-white px-4 font-semibold text-slate-800 ring-1 ring-slate-200 hover:bg-slate-100">
                            <span x-show="! copied">🔗 Copy link</span><span x-show="copied" x-cloak>✓ Copied</span>
                        </button>
                    </div>
                </details>
            </section>
        @endif

        {{-- My shops --}}
        <section class="mt-8">
            <h2 class="text-lg font-bold text-slate-900">My shops and restaurants <span class="text-sm font-medium text-slate-500">({{ $shops->count() }})</span></h2>
            @if ($shops->isEmpty())
                <p class="mt-3 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600" data-testid="fa-no-shops">No shops yet. Sign up the first one above and it appears here.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($shops as $row)
                        <x-field-associate.shop-progress :row="$row" wire:key="fa-shop-{{ $row['store']->id }}" />
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
