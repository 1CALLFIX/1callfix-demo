@php $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2); @endphp
<div wire:poll.30s>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-2xl font-bold">Parcel Dashboard</h1>
        <div class="flex flex-wrap gap-2">
            @if ($franchises->count() > 1)
                <select wire:model.live="franchiseId" class="border rounded px-3 py-2 text-sm" aria-label="Franchise">
                    <option value="">All my franchises</option>
                    @foreach ($franchises as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            @endif
            <a href="{{ route('admin.parcel-orders.index') }}" wire:navigate class="border rounded px-3 py-2 text-sm hover:bg-gray-50">Parcel Orders →</a>
        </div>
    </div>

    <p class="text-sm text-gray-500 mb-4">Today so far ({{ now($tz)->format('D j M') }}). Refreshes every 30 seconds.</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Orders today</div>
            <div class="text-xl font-semibold font-mono">{{ $todayTotal }}</div>
            <div class="text-xs text-gray-500 mt-1">{{ $todayDelivered }} delivered · {{ $todayInProgress }} on the way · {{ $todayCancelled }} cancelled</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Waiting for a rider</div>
            <div @class(['text-xl font-semibold font-mono', 'text-red-700' => $waitingCount > 0])>{{ $waitingCount }}</div>
            <div class="text-xs text-gray-500 mt-1">Assign by hand from Parcel Orders if no one accepts.</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Average time to a rider</div>
            <div class="text-xl font-semibold font-mono">{{ $avgAssignMinutes !== null ? $avgAssignMinutes.' min' : '—' }}</div>
            <div class="text-xs text-gray-500 mt-1">{{ $assignedTodayCount }} accepted today</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Scheduled pickups ahead</div>
            <div class="text-xl font-semibold font-mono">{{ $scheduledUpcoming }}</div>
        </x-ui.card>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Riders online now</div>
            <div class="text-xl font-semibold font-mono">{{ $ridersOnline }} <span class="text-sm font-normal text-gray-500">of {{ $ridersTotal }}</span></div>
            <div class="text-xs text-gray-500 mt-1">{{ $ridersOnJob }} on a job</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Rider cash dues not recovered</div>
            <div @class(['text-xl font-semibold font-mono', 'text-amber-700' => $duesOutstanding > 0])>{{ $fmt($duesOutstanding) }}</div>
        </x-ui.card>
        @if ($earnings)
            <x-ui.card>
                <div class="text-xs text-gray-500 mb-1">Franchise share from parcel, today</div>
                <div class="text-xl font-semibold font-mono text-green-700">{{ $fmt($earnings['today']) }}</div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-xs text-gray-500 mb-1">Franchise share from parcel, this month</div>
                <div class="text-xl font-semibold font-mono text-green-700">{{ $fmt($earnings['month']) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $earnings['month_orders'] }} delivered parcels</div>
            </x-ui.card>
        @endif
    </div>

    <x-ui.card>
        <h2 class="text-base font-semibold mb-2">Waiting for a rider, oldest first</h2>
        @if ($waiting->isEmpty())
            <p class="text-sm text-gray-500">None. Every parcel has a rider.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2 pr-3 font-medium">Order</th>
                        <th class="py-2 pr-3 font-medium">Zone</th>
                        <th class="py-2 pr-3 font-medium">Payment</th>
                        <th class="py-2 font-medium">Waiting</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($waiting as $o)
                        <tr class="border-b last:border-0" wire:key="w-{{ $o->id }}">
                            <td class="py-2 pr-3 font-mono">{{ $o->code }}</td>
                            <td class="py-2 pr-3">{{ $o->zone?->name }}</td>
                            <td class="py-2 pr-3">{{ ucfirst($o->payment_method) }}</td>
                            <td class="py-2">{{ ($o->dispatch_released_at ?? $o->created_at)->diffForHumans(null, true) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($waitingCount > $waiting->count())
                <p class="mt-2 text-xs text-gray-500">Showing the oldest {{ $waiting->count() }} of {{ $waitingCount }}.</p>
            @endif
        @endif
    </x-ui.card>
</div>
