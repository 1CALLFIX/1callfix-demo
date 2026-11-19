{{-- Account → Rent out your things (Rent anything, owner decision 2026-11-18): your posted items, one main action.
     Account → Second-hand ($sale, 2026-11-19): your used things for sale, with Mark as sold. --}}
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $sale ? 'Your second-hand posts' : 'Rent out your things' }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $sale ? 'Turn things you no longer use into money. We check each post, then people near you call or WhatsApp you. No commission.' : 'Earn from things you do not use every day. We check each post, then people near you call or WhatsApp you. No commission.' }}</p>

        @if ($notice) <div role="status" class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="items-notice">{{ $notice }}</div> @endif
        @error('items') <div role="alert" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="items-error">{{ $message }}</div> @enderror

        @if (! $open && $items->isEmpty())
            <div role="status" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" data-testid="items-not-open">Posting is not open in your area yet. Please check again soon.</div>
        @elseif ($blocked)
            <div role="status" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="items-blocked">{{ $blocked }}</div>
        @else
            <a href="{{ route($createRoute) }}" wire:navigate class="mt-6 flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 text-base font-semibold text-white shadow-sm hover:bg-blue-700" data-testid="items-new">
                ➕ {{ $sale ? 'Post a used thing' : 'Rent out something' }}
            </a>
        @endif
        <p class="mt-2 text-xs text-slate-500">{{ $up }} of {{ $max }} {{ $sale ? 'used things' : 'items' }} up or waiting for review.</p>
        @if ($credits > 0)
            <div role="status" class="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200" data-testid="items-credits">📦 You have {{ $credits }} Featured {{ $credits === 1 ? 'post' : 'posts' }} left in your pack{{ $creditsUntil ? ' (use by '.$creditsUntil->format('j M').')' : '' }}. Tap "Get more calls" on a post to use one.</div>
        @endif

        @if ($items->isNotEmpty())
            <ul class="mt-6 space-y-3">
                @foreach ($items as $item)
                    <li class="cf-card flex gap-3 p-3" wire:key="ri-{{ $item->id }}" data-testid="items-row">
                        <a href="{{ route($editRoute, $item->id) }}" wire:navigate class="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                            @if ($item->coverUrl()) <img src="{{ $item->coverUrl() }}" alt="" class="h-full w-full object-cover"> @else <span class="grid h-full place-items-center text-2xl">📦</span> @endif
                        </a>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route($editRoute, $item->id) }}" wire:navigate class="truncate font-semibold text-slate-900 hover:underline">{{ $item->name }}</a>
                                @php
                                    [$label, $colour] = match (true) {
                                        $item->isSold() => ['Sold', 'bg-violet-100 text-violet-800'],
                                        $item->status === 'approved' && $item->hidden_for_reports_at !== null => ['Being checked', 'bg-amber-100 text-amber-800'],
                                        $item->status === 'approved' && $item->isExpired() => ['Ran out', 'bg-slate-200 text-slate-700'],
                                        $item->status === 'approved' && ! $item->is_active => ['Hidden by you', 'bg-slate-200 text-slate-700'],
                                        $item->status === 'approved' => ['Up', 'bg-emerald-100 text-emerald-800'],
                                        $item->status === 'in_review' => ['Waiting for review', 'bg-amber-100 text-amber-800'],
                                        $item->status === 'rejected' => ['Needs changes', 'bg-red-100 text-red-700'],
                                        default => ['Draft', 'bg-slate-100 text-slate-700'],
                                    };
                                @endphp
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $colour }}" data-testid="items-badge">{{ $label }}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-slate-600">₹{{ number_format((float) $item->price) }}{{ $sale ? ($item->negotiable ? ' · can be discussed' : '') : ' per '.mb_strtolower((string) $item->price_unit) }}@if ($item->expires_at && $item->status === 'approved' && ! $item->isSold()) · {{ $item->isExpired() ? 'ran out' : 'up until' }} {{ $item->expires_at->format('j M') }}@endif</p>
                            @if ($item->isBoostedNow())
                                <p class="mt-1"><span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-semibold text-orange-800" data-testid="items-boosted">🚀 Boosted until {{ $item->boosted_until->format('j M') }}</span></p>
                            @elseif ($item->isFeaturedNow())
                                <p class="mt-1"><span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800" data-testid="items-featured">⭐ Featured{{ $item->featured_until ? ' until '.$item->featured_until->format('j M') : '' }}</span></p>
                            @endif
                            @if ($item->isLive() && ($packsOn($item) || $credits > 0) && $packFor !== $item->id)
                                <button type="button" wire:click="openPacks({{ $item->id }})" class="mt-2 min-h-11 rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-4 text-sm font-bold text-white shadow-sm" data-testid="items-packs">🚀 Get more calls</button>
                            @endif
                            @if ($packFor === $item->id && $packItem)
                                {{-- Paid packs (owner 2026-11-19): tap a pack, then pay. Only packs switched on show. --}}
                                <div class="mt-3 rounded-2xl border-2 border-amber-300 bg-amber-50 p-3" data-testid="packs-panel">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold text-slate-900">Get more calls for this post</p>
                                        <button type="button" wire:click="closePacks" class="grid h-11 w-11 place-items-center rounded-full text-slate-500 hover:bg-amber-100" aria-label="Close">✕</button>
                                    </div>
                                    @error('packs') <div role="alert" class="mt-2 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="packs-error">{{ $message }}</div> @enderror
                                    @if ($credits > 0)
                                        <button type="button" wire:click="useCredit({{ $item->id }})" class="mt-2 flex min-h-12 w-full items-center justify-between gap-2 rounded-xl bg-emerald-600 px-4 text-left text-sm font-bold text-white" data-testid="packs-use-credit">
                                            <span>⭐ Use 1 Featured from your pack</span><span class="rounded-full bg-white/20 px-2 py-0.5 text-xs">{{ $credits }} left · free</span>
                                        </button>
                                    @endif
                                    <div class="mt-2 grid gap-2">
                                        @foreach ($offer as $o)
                                            @php
                                                [$icon, $name, $line, $ring] = match ($o['key']) {
                                                    'featured' => ['⭐', 'Featured', 'Top of the list with a Featured tag for '.$o['days'].' days', 'ring-amber-400'],
                                                    'boost' => ['🚀', 'Boost', 'Featured, plus a big card in the Boosted row at the top of the page for '.$o['days'].' days', 'ring-orange-500'],
                                                    default => ['📦', $o['count'].' Featured posts', '1 now on this post, the rest on any of your posts within '.\App\Support\RentalItemSettings::get('packs.bulk_valid_days', $packItem->scope()).' days', 'ring-violet-500'],
                                                };
                                                $picked = $pack === $o['key'];
                                            @endphp
                                            <button type="button" wire:click="pickPack('{{ $o['key'] }}')" @disabled($o['blocked'])
                                                    @class(['w-full rounded-xl bg-white p-3 text-left shadow-sm ring-1 ring-slate-200 transition disabled:opacity-60', 'ring-2 '.$ring => $picked])
                                                    aria-pressed="{{ $picked ? 'true' : 'false' }}" data-testid="pack-{{ $o['key'] }}">
                                                <span class="flex items-start justify-between gap-2">
                                                    <span class="font-bold text-slate-900">{{ $icon }} {{ $name }}
                                                        @if ($o['key'] === 'boost') <span class="ml-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-800">Most calls</span> @endif
                                                        @if ($o['saving']) <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Save {{ $o['saving'] }}%</span> @endif
                                                    </span>
                                                    <span class="shrink-0 text-right font-bold text-slate-900">
                                                        @if ($o['normal']) <s class="mr-1 text-xs font-normal text-slate-400">₹{{ number_format($o['normal']) }}</s> @endif₹{{ number_format($o['price']) }}
                                                    </span>
                                                </span>
                                                <span class="mt-1 block text-sm text-slate-600">{{ $line }}</span>
                                                @if ($o['blocked']) <span class="mt-1 block text-sm text-amber-800">{{ $o['blocked'] }}</span> @endif
                                            </button>
                                        @endforeach
                                    </div>
                                    @if ($pack !== '' && ($chosen = collect($offer)->firstWhere('key', $pack)))
                                        <div class="sticky bottom-20 mt-3 grid gap-2 rounded-xl bg-white p-2 shadow-lg ring-1 ring-slate-200 sm:bottom-2 sm:grid-cols-2" data-testid="packs-pay">
                                            @if ($online)
                                                <button type="button" wire:click="payPack('online')" wire:loading.attr="disabled" class="min-h-12 rounded-xl bg-blue-600 px-4 font-bold text-white" data-testid="packs-pay-online">Pay ₹{{ number_format($chosen['price']) }} online</button>
                                            @endif
                                            <button type="button" wire:click="payPack('wallet')" wire:loading.attr="disabled" @disabled($wallet < $chosen['price']) class="min-h-12 rounded-xl border border-blue-300 bg-white px-4 font-semibold text-blue-800 disabled:opacity-50" data-testid="packs-pay-wallet">Pay from wallet (₹{{ number_format($wallet) }})</button>
                                        </div>
                                    @endif
                                </div>
                            @endif
                            @if ($item->status === 'rejected' && $item->rejection_reason)
                                <p class="mt-1 text-sm text-red-700">{{ $item->rejection_reason }}</p>
                            @endif
                            @if ($item->status === 'approved' && ! $item->isSold())
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @if ($sale)
                                        <button type="button" wire:click="markSold({{ $item->id }})" wire:confirm="Mark &quot;{{ $item->name }}&quot; as sold? It stops showing to customers." class="min-h-11 rounded-xl bg-violet-600 px-4 text-sm font-semibold text-white" data-testid="items-sold">✅ Mark as sold</button>
                                    @endif
                                    @if ($item->isExpired())
                                        <button type="button" wire:click="renew({{ $item->id }})" class="min-h-11 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white" data-testid="items-renew">🔄 Renew</button>
                                    @elseif ($sale)
                                    @elseif ($item->is_active)
                                        <button type="button" wire:click="notAvailable({{ $item->id }})" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800" data-testid="items-off">⏸️ Not available now</button>
                                    @else
                                        <button type="button" wire:click="availableAgain({{ $item->id }})" class="min-h-11 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white" data-testid="items-on">Available again</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($online)
        @assets
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        @endassets
    @endif
    @script
    <script>
        $wire.on('razorpay-open', (e) => {
            const o = e.order ?? e[0]?.order;
            if (!o || !window.Razorpay) return;
            new window.Razorpay({
                key: o.razorpay_key_id ?? o.key_id,
                order_id: o.razorpay_order_id,
                amount: o.amount,
                currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                prefill: @js(\App\Support\RazorpayPrefill::forCurrentUser()),
                description: 'Pack for your post',
                handler: () => window.location.reload(),
            }).open();
        });
    </script>
    @endscript
</div>
