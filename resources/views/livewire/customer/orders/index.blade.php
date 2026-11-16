{{-- My Orders: everything the customer has ordered or booked, from every module, in one list (owner 2026-11-16).
     Each row opens that order's own page; each module has its own colour and icon so the list reads at a glance. --}}
@php
    $statusFilters = ['' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $meta = [
        'service' => ['Services', 'wrench', 'bg-blue-100 text-blue-700'],
        'parcel'  => ['Parcels', 'activity', 'bg-amber-100 text-amber-700'],
        'shop'    => ['Shop', 'shopping-bag', 'bg-emerald-100 text-emerald-700'],
        'food'    => ['Food', 'tag', 'bg-orange-100 text-orange-700'],
        'hotel'   => ['Hotels', 'building', 'bg-sky-100 text-sky-700'],
        'hall'    => ['Function halls', 'home', 'bg-rose-100 text-rose-700'],
        'event'   => ['Events', 'ticket', 'bg-violet-100 text-violet-700'],
    ];
    $tones = [
        'active'    => ['bg-blue-50 text-blue-700 ring-blue-600/20', 'In progress'],
        'completed' => ['bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'Completed'],
        'cancelled' => ['bg-rose-50 text-rose-700 ring-rose-600/20', 'Cancelled'],
    ];
    $chip = fn (bool $on) => 'shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 '
        .($on ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200');
@endphp

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold tracking-tight">My Orders</h1>
    <p class="mt-1 text-sm text-slate-500">Everything you have booked or ordered, in one place.</p>

    {{-- Which module (only the ones you have used) --}}
    @if ($kinds->isNotEmpty())
        <div class="-mx-4 mt-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:px-0 [&::-webkit-scrollbar]:hidden" role="tablist" aria-label="Filter by module">
            <button wire:click="$set('kind', '')" class="{{ $chip($kind === '') }}">All orders</button>
            @foreach ($kinds as $k)
                <button wire:click="$set('kind', '{{ $k }}')" class="{{ $chip($kind === $k) }}">{{ $meta[$k][0] }}</button>
            @endforeach
        </div>
    @endif

    {{-- Progress --}}
    <div class="mt-2 flex flex-wrap gap-2" role="tablist" aria-label="Filter by progress">
        @foreach ($statusFilters as $key => $label)
            <button wire:click="$set('filter', '{{ $key }}')" @class([
                'rounded-lg border px-3 py-1 text-xs font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600',
                'border-blue-600 bg-blue-50 text-blue-700' => $filter === $key,
                'border-slate-200 text-slate-500 hover:bg-slate-50' => $filter !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @forelse ($orders as $o)
        @php [$kindLabel, $kindIcon, $kindColor] = $meta[$o['kind']]; @endphp
        <a href="{{ $o['href'] }}" wire:navigate
           class="mt-3 flex gap-3 rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
            <span aria-hidden="true" class="grid h-12 w-12 shrink-0 place-items-center rounded-xl {{ $kindColor }}">
                <x-icon :name="$kindIcon" class="h-6 w-6" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-900">{{ $o['title'] }}</p>
                        <p class="text-xs text-slate-500">{{ $kindLabel }} · {{ $o['code'] }} · {{ $o['at']?->format('j M Y') }}</p>
                    </div>
                    @if ($o['kind'] === 'service')
                        <x-customer.order-status :status="$o['status']" :paid="$o['paid']" :cash="$o['cash']" />
                    @else
                        @php [$tone, $toneLabel] = $tones[$o['bucket']]; @endphp
                        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $tone }}">{{ \Illuminate\Support\Str::headline($o['status']) ?: $toneLabel }}</span>
                    @endif
                </div>
                <div class="mt-2 flex items-center justify-between text-sm">
                    <span class="truncate text-slate-600">{{ $o['line'] }}</span>
                    <span class="shrink-0 pl-3 font-medium text-slate-900">{{ $currencySymbol }}{{ number_format($o['amount'], 2) }}</span>
                </div>
            </div>
        </a>
    @empty
        <div class="mt-6 rounded-2xl border border-dashed border-slate-300 p-8 text-center">
            <p class="text-sm text-slate-600">No orders here yet.</p>
            <a href="{{ route('customer.home') }}" wire:navigate
               class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                Start exploring
            </a>
        </div>
    @endforelse

    <div class="mt-5">{{ $orders->links() }}</div>
</div>
