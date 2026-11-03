@php $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2); @endphp
<div wire:poll.30s>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-2xl font-bold">Food Dashboard</h1>
        <div class="flex flex-wrap gap-2">
            @if ($franchises->count() > 1)
                <select wire:model.live="franchiseId" class="border rounded px-3 py-2 text-sm" aria-label="Franchise">
                    <option value="">All my franchises</option>
                    @foreach ($franchises as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            @endif
            <a href="{{ route('admin.marketplace-orders.index') }}" wire:navigate class="border rounded px-3 py-2 text-sm hover:bg-gray-50">Orders →</a>
            <a href="{{ route('admin.shop-returns.index', ['statusFilter' => 'food']) }}" wire:navigate class="border rounded px-3 py-2 text-sm hover:bg-gray-50">Food problems →</a>
        </div>
    </div>

    <p class="text-sm text-gray-500 mb-4">Today so far ({{ now($tz)->format('D j M') }}). Refreshes every 30 seconds.</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Food orders today</div>
            <div class="text-xl font-semibold font-mono" data-testid="food-today">{{ $todayTotal }}</div>
            <div class="text-xs text-gray-500 mt-1">{{ $todayDelivered }} done · {{ $todayOpen }} open · {{ $todayCancelled }} cancelled</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Not accepted in time today</div>
            <div @class(['text-xl font-semibold font-mono', 'text-red-700' => $notAccepted > 0])>{{ $notAccepted }}</div>
            <div class="text-xs text-gray-500 mt-1">Cancelled and refunded in full. Call the restaurant if this keeps happening.</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Restaurants</div>
            <div class="text-xl font-semibold font-mono">{{ $restaurantsOpen }} open <span class="text-sm text-gray-500">of {{ $restaurantsLive }}</span></div>
            <div class="text-xs text-gray-500 mt-1">{{ $restaurantsBusy }} busy
                @if ($restaurantsNoFssai) · <span class="text-red-700">{{ $restaurantsNoFssai }} without FSSAI (hidden)</span> @endif</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Open food problems</div>
            <div @class(['text-xl font-semibold font-mono', 'text-amber-700' => $problemsOpen > 0])>{{ $problemsOpen }}</div>
            <div class="text-xs text-gray-500 mt-1">This month: {{ $refundMonth['count'] }} refunded · restaurants {{ $fmt($refundMonth['restaurant']) }} · platform {{ $fmt($refundMonth['platform']) }}</div>
        </x-ui.card>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <x-ui.card>
            <h2 class="font-semibold mb-2">Waiting for the restaurant to accept</h2>
            @forelse ($waitingAccept as $o)
                <div class="flex justify-between border-t py-1.5 text-sm">
                    <span>{{ $o->code }}<span class="block text-xs text-gray-500">{{ $o->store?->name }}</span></span>
                    <span @class(['text-xs', 'text-red-700 font-semibold' => $o->accept_by->diffInMinutes(now(), false) > -3])>by {{ $o->accept_by->timezone($tz)->format('g:i A') }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">None.</p>
            @endforelse
        </x-ui.card>
        <x-ui.card>
            <h2 class="font-semibold mb-2">Past their ready time</h2>
            @forelse ($late as $o)
                <div class="flex justify-between border-t py-1.5 text-sm">
                    <span>{{ $o->code }}<span class="block text-xs text-gray-500">{{ $o->store?->name }}</span></span>
                    <span class="text-xs text-red-700">{{ (int) $o->ready_by->diffInMinutes(now()) }} min late</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">None.</p>
            @endforelse
        </x-ui.card>
        <x-ui.card>
            <h2 class="font-semibold mb-2">Rider strikes (last {{ $strikeDays }} days)</h2>
            <p class="text-xs text-gray-500 mb-1">Food damaged on the way. Riders are never charged.</p>
            @forelse ($strikes as $row)
                <div class="flex justify-between border-t py-1.5 text-sm">
                    <span>{{ $row->deliveryWorker?->user?->name ?? 'Rider #'.$row->field_worker_id }}</span>
                    <span class="font-mono">{{ $row->n }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">None.</p>
            @endforelse
        </x-ui.card>
    </div>
</div>
