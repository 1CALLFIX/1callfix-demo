<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ $store->name }}</h1>
            <p class="text-sm text-slate-500">{{ $store->address_line }}</p>
        </div>
        @if ($store->isSellable())
            <button type="button" wire:click="toggleOpen"
                    @class(['inline-flex min-h-11 items-center gap-2 rounded-full px-5 text-sm font-semibold',
                            'bg-emerald-600 text-white' => $store->is_open, 'bg-slate-200 text-slate-700' => ! $store->is_open])>
                <span @class(['h-2.5 w-2.5 rounded-full', 'bg-white' => $store->is_open, 'bg-slate-500' => ! $store->is_open])></span>
                {{ $store->is_open ? 'Open — tap to close' : 'Closed — tap to open' }}
            </button>
        @endif
    </div>

    @if ($notice)
        <div role="status" class="rounded-lg bg-blue-50 px-4 py-2.5 text-sm text-blue-900">{{ $notice }}</div>
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
            <p class="font-semibold">Your shop is approved.</p>
            <p class="mt-1">Shopping opens to customers in your area soon. Keep your products and stock up to date.</p>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-ui.card class="!p-4"><p class="text-xs text-slate-500">Orders today</p><p class="mt-1 text-2xl font-bold">{{ $ordersToday }}</p></x-ui.card>
        <x-ui.card class="!p-4"><p class="text-xs text-slate-500">Sales today</p><p class="mt-1 text-2xl font-bold">{{ $currencySymbol }}{{ number_format($salesToday, 0) }}</p></x-ui.card>
        <a href="{{ route('seller.orders') }}" wire:navigate><x-ui.card class="!p-4"><p class="text-xs text-slate-500">Open orders</p><p class="mt-1 text-2xl font-bold">{{ $activeOrders }}</p></x-ui.card></a>
        <a href="{{ route('seller.products') }}" wire:navigate><x-ui.card class="!p-4"><p class="text-xs text-slate-500">Products</p><p class="mt-1 text-2xl font-bold">{{ $productCount }}</p>
            @if ($awaitingApproval)<p class="text-xs text-amber-700">{{ $awaitingApproval }} awaiting approval</p>@endif</x-ui.card></a>
    </div>

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
