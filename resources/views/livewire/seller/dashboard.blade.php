<div class="space-y-5">
    {{-- Partner apps 2026-11-04: open/closed as one big switch card, the same as the rider's online switch. --}}
    @php $word = $store->isRestaurant() ? 'restaurant' : 'shop'; @endphp
    <div @class(['flex items-center justify-between gap-3 rounded-2xl p-4 text-white',
        'bg-emerald-600' => $store->isSellable() && $store->is_open, 'bg-slate-800' => ! ($store->isSellable() && $store->is_open)])>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold tracking-tight">{{ $store->name }}</h1>
            <p class="truncate text-xs opacity-90">{{ $store->address_line }}</p>
            @if ($store->isSellable())
                <p class="mt-1 text-sm font-semibold">{{ $store->is_open ? 'Open — customers can order' : 'Closed — customers cannot order' }}</p>
            @endif
        </div>
        @if ($store->isSellable())
            <button type="button" wire:click="toggleOpen" role="switch" aria-checked="{{ $store->is_open ? 'true' : 'false' }}"
                    aria-label="{{ $store->is_open ? 'Close the '.$word : 'Open the '.$word }}" data-testid="open-switch"
                    @class(['relative inline-flex h-10 w-[4.5rem] shrink-0 items-center rounded-full ring-2',
                        'bg-white/30 ring-white/70' => $store->is_open, 'bg-white/20 ring-white/40' => ! $store->is_open])>
                <span @class(['absolute h-8 w-8 rounded-full shadow', 'right-1 bg-white' => $store->is_open, 'left-1 bg-slate-300' => ! $store->is_open])></span>
            </button>
        @endif
    </div>

    @if ($notice)
        <div role="status" class="rounded-lg bg-blue-50 px-4 py-2.5 text-sm text-blue-900">{{ $notice }}</div>
    @endif

    @if ($store->isRestaurant())
        {{-- Food F1: the kitchen at a glance — FSSAI, opening hours, busy mode. --}}
        @if ($store->missingFssai())
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Add your FSSAI licence number.</p>
                <p class="mt-1">The law needs it for every food business. Your restaurant cannot be approved or open without it. <a href="{{ route('seller.shop') }}" wire:navigate class="font-medium underline">Add it now</a></p>
            </div>
        @endif
        @if ($store->is_open && ! $store->withinHours())
            <div class="rounded-2xl border border-slate-300 bg-white p-4 text-sm text-slate-700">You are outside your opening hours, so customers cannot order now. <a href="{{ route('seller.shop') }}" wire:navigate class="font-medium underline">Change hours</a></div>
        @endif
        @if ($busyChoices)
            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-semibold">Kitchen time: {{ $store->currentPrepMinutes() }} min</p>
                        <p class="text-sm text-slate-600">
                            @if ($store->isBusy()) Busy until {{ $store->localNow($store->busy_until)->format('h:i A') }} (+{{ $store->busy_extra_minutes }} min).
                            @else Too many orders? Tell customers it will take longer. @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($busyChoices as $m)
                            <button type="button" wire:click="setBusy({{ $m }})" @class(['min-h-10 rounded-full border px-4 text-sm font-medium',
                                'border-amber-500 bg-amber-50 text-amber-900' => $store->isBusy() && $store->busy_extra_minutes === $m, 'border-slate-300 bg-white' => ! ($store->isBusy() && $store->busy_extra_minutes === $m)])>+{{ $m }} min</button>
                        @endforeach
                        @if ($store->isBusy())
                            <button type="button" wire:click="clearBusy" class="min-h-10 rounded-full bg-slate-800 px-4 text-sm font-medium text-white">Not busy</button>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- Where the shop stands, in the order the seller has to clear it. --}}
    @if ($store->approval_status === 'pending')
        <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Your shop is waiting for approval.</p>
            <p class="mt-1">Add your products now — they are ready the moment we approve your shop.
                Documents: <span class="font-medium">{{ ucfirst($kycStatus ?? 'pending') }}</span>.</p>
        </div>
    @elseif ($store->approval_status === 'rejected')
        <div class="rounded-2xl border border-red-300 bg-red-50 p-4 text-sm text-red-900">
            <p class="font-semibold">Your shop was not approved.</p>
            @if ($store->approval_note)<p class="mt-1">{{ $store->approval_note }}</p>@endif
            <p class="mt-1">Fix the details on the Shop page and contact support to review it again.</p>
        </div>
    @elseif (! $store->is_active)
        <div class="rounded-2xl border border-slate-300 bg-white p-4 text-sm text-slate-700">Your shop is switched off by the {{ \App\Models\Setting::get('branding.platform_name', '1CallFix') }} team. Contact support.</div>
    @elseif (! $moduleLive)
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <p class="font-semibold">Your {{ $word }} is approved.</p>
            <p class="mt-1">Shopping opens to customers in your area soon. Keep your products and stock up to date.</p>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-ui.card class="!p-4"><p class="text-xs text-slate-500">Orders today</p><p class="mt-1 text-2xl font-bold">{{ $ordersToday }}</p></x-ui.card>
        <x-ui.card class="!p-4"><p class="text-xs text-slate-500">Sales today</p><p class="mt-1 text-2xl font-bold">{{ $currencySymbol }}{{ number_format($salesToday, 0) }}</p></x-ui.card>
        <a href="{{ route('seller.orders') }}" wire:navigate><x-ui.card class="!p-4"><p class="text-xs text-slate-500">Open orders</p><p class="mt-1 text-2xl font-bold">{{ $activeOrders }}</p></x-ui.card></a>
        <a href="{{ route('seller.products') }}" wire:navigate><x-ui.card class="!p-4"><p class="text-xs text-slate-500">{{ $store->isRestaurant() ? 'Dishes' : 'Products' }}</p><p class="mt-1 text-2xl font-bold">{{ $productCount }}</p>
            @if ($store->isRestaurant() && $soldOutToday)<p class="text-xs text-red-700" data-testid="sold-out-count">{{ $soldOutToday }} sold out today</p>@endif
            @if ($awaitingApproval)<p class="text-xs text-amber-700">{{ $awaitingApproval }} awaiting approval</p>@endif</x-ui.card></a>
    </div>
    @if ($openProblems)
        <a href="{{ route('seller.orders', ['tab' => 'returns']) }}" wire:navigate class="mt-3 block rounded-2xl border-2 border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900" data-testid="open-problems">
            {{ $openProblems }} {{ $store->isRestaurant() ? ($openProblems === 1 ? 'problem' : 'problems') : ($openProblems === 1 ? 'return' : 'returns') }} waiting for you{{ $store->isRestaurant() ? ' — Agree or Dispute' : '' }} →
        </a>
    @endif

    @if ($productCount === 0)
        <x-ui.card>
            <p class="font-semibold">Add your first product</p>
            <p class="mt-1 text-sm text-slate-600">Name, price, stock and a photo is all it takes.</p>
            <a href="{{ route('seller.products', ['new' => 1]) }}" wire:navigate class="mt-3 inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white">Add a product</a>
        </x-ui.card>
    @endif

    @if ($lowStock->isNotEmpty())
        <x-ui.card>
            <h2 class="text-sm font-semibold">Running low</h2>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach ($lowStock as $p)
                    <li class="flex items-center justify-between py-2"><span>{{ $p->name }}</span>
                        <span @class(['font-semibold', 'text-red-600' => $p->stock <= 0, 'text-amber-700' => $p->stock > 0])>{{ $p->stock <= 0 ? 'Out of stock' : $p->stock.' left' }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
