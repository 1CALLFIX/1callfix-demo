{{-- Account → My field work (field associates, owner 2026-11-23): target bar + rewards, the QR to sign up a shop,
     then every shop with its progress bar. Private page (no Share button). --}}
@php
    $money = fn (float $a) => \App\Services\FieldAssociates\FieldAssociateTracker::money($a);
    $link = $associate->link();
    $platform = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $whatsApp = 'https://wa.me/?text='.rawurlencode("Open your shop or restaurant on {$platform}. Sign up here: {$link}");
    $targetPercent = $target > 0 ? min(100, (int) round($stats['live'] / $target * 100)) : null;
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

        {{-- Month: target bar and rewards --}}
        <section class="mt-6 rounded-2xl bg-gradient-to-br from-blue-600 to-violet-600 p-5 text-white shadow-sm" data-testid="fa-month">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold">{{ $month->format('F Y') }}</h2>
                <div class="flex gap-1 rounded-full bg-white/15 p-1 text-sm">
                    <button type="button" wire:click="showMonth(0)" @class(['min-h-9 rounded-full px-3 font-semibold', 'bg-white text-blue-700' => $monthsAgo === 0, 'text-white' => $monthsAgo !== 0])>This month</button>
                    <button type="button" wire:click="showMonth(1)" @class(['min-h-9 rounded-full px-3 font-semibold', 'bg-white text-blue-700' => $monthsAgo === 1, 'text-white' => $monthsAgo !== 1])>Last month</button>
                </div>
            </div>

            @if ($targetPercent !== null)
                <p class="mt-4 text-sm text-white/90">Target: {{ $target }} shops live</p>
                <div class="mt-1.5 h-4 w-full overflow-hidden rounded-full bg-white/25" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $targetPercent }}" aria-label="Monthly target {{ $targetPercent }}%">
                    <div class="h-full rounded-full bg-emerald-400" style="width: {{ max(3, $targetPercent) }}%" data-testid="fa-target-bar"></div>
                </div>
                <p class="mt-1 text-sm font-semibold" data-testid="fa-target-text">{{ $stats['live'] }} of {{ $target }} done{{ $stats['live'] >= $target ? ' 🎉 Target reached!' : '' }}</p>
            @endif

            <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-white/15 p-3"><dt class="text-xs text-white/80">Signed up</dt><dd class="text-2xl font-bold" data-testid="fa-signed-up">{{ $stats['signed_up'] }}</dd></div>
                <div class="rounded-xl bg-white/15 p-3"><dt class="text-xs text-white/80">Live ✅</dt><dd class="text-2xl font-bold" data-testid="fa-live">{{ $stats['live'] }}</dd></div>
                <div class="rounded-xl bg-white/15 p-3"><dt class="text-xs text-white/80">First orders 🎉</dt><dd class="text-2xl font-bold" data-testid="fa-first-orders">{{ $stats['first_orders'] }}</dd></div>
            </dl>
            <div class="mt-3 rounded-xl bg-white p-3 text-center text-slate-900">
                <p class="text-xs font-medium text-slate-500">Rewards earned in {{ $month->format('F') }}</p>
                <p class="text-3xl font-extrabold text-emerald-600" data-testid="fa-earned">{{ $money($stats['earned']) }}</p>
                @if ($rewardLive > 0 || $rewardFirst > 0)
                    <p class="mt-1 text-xs text-slate-500">{{ $money($rewardLive) }} when a shop goes live · {{ $money($rewardFirst) }} on its first order</p>
                @endif
            </div>
        </section>

        {{-- Sign up a new shop --}}
        @if ($associate->is_active)
            <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" data-testid="fa-add-shop">
                <h2 class="text-lg font-bold text-slate-900">➕ Add a new shop or restaurant</h2>
                <ol class="mt-2 space-y-1 text-sm text-slate-700">
                    <li><span class="font-semibold text-blue-700">1.</span> Ask the owner to scan this QR with their phone camera.</li>
                    <li><span class="font-semibold text-blue-700">2.</span> Help them fill the form: mobile code, shop name, address, documents.</li>
                    <li><span class="font-semibold text-blue-700">3.</span> Add their products or menu. The shop shows up below.</li>
                </ol>
                <div class="mx-auto mt-4 aspect-square w-56 max-w-full rounded-xl bg-white p-2 ring-1 ring-slate-200" data-qr="{{ $link }}" wire:ignore aria-label="QR code to sign up a shop with your code" data-testid="fa-qr"></div>
                <div class="mt-4 grid gap-2 sm:grid-cols-2" x-data="{ copied: false }">
                    <a href="{{ $whatsApp }}" target="_blank" rel="noopener" class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700" data-testid="fa-whatsapp">📲 Send link on WhatsApp</a>
                    <button type="button" x-on:click="navigator.clipboard?.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 2000)" class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-slate-100 px-4 font-semibold text-slate-800 hover:bg-slate-200">
                        <span x-show="! copied">🔗 Copy link</span><span x-show="copied" x-cloak>✓ Copied</span>
                    </button>
                </div>
                <a href="{{ $link }}" class="mt-3 flex min-h-11 items-center justify-center rounded-xl text-sm font-medium text-blue-700 underline underline-offset-4" data-testid="fa-fill-here">Fill the form on this phone instead (you will be signed out)</a>
            </section>
        @endif

        {{-- My shops --}}
        <section class="mt-8">
            <h2 class="text-lg font-bold text-slate-900">My shops <span class="text-sm font-medium text-slate-500">({{ $shops->count() }})</span></h2>
            @if ($shops->isEmpty())
                <p class="mt-3 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600" data-testid="fa-no-shops">No shops yet. Show your QR at the first shop and it appears here.</p>
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
