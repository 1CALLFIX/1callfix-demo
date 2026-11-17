@php
    // Change against last month: green up / red down, "new" when last month was zero.
    $delta = function ($a, $b) {
        if ($b == 0) { return $a > 0 ? ['new', 'text-emerald-700'] : ['', '']; }
        $d = round(100 * ($a - $b) / $b);
        return [($d >= 0 ? '▲ ' : '▼ ').abs($d).'%', $d >= 0 ? 'text-emerald-700' : 'text-rose-700'];
    };
    $tile = 'rounded-2xl p-4 text-white shadow';
@endphp
<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-1">
        <h1 class="text-2xl font-bold">Franchise Insights</h1>
        <div class="flex flex-wrap gap-2">
            @if ($franchises->count() > 1)
                <select wire:model.live="franchiseId" class="border rounded px-3 py-2 text-sm">
                    @foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="month" class="border rounded px-3 py-2 text-sm">
                @foreach ($months as $m)
                    <option value="{{ $m }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('F Y') }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <p class="text-sm text-gray-500 mb-4">Live from the real bookings in {{ $franchise->name }}. Home-service bookings today; the other businesses join as they go live. Arrows compare with the month before.</p>

    @if ($showFunnel)
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-insights-funnel>
            @foreach ([
                ['Bookings placed', $now['placed'], $before['placed'], 'bg-gradient-to-br from-blue-600 to-sky-500'],
                ['Completed jobs', $now['completed'], $before['completed'], 'bg-gradient-to-br from-emerald-600 to-teal-500'],
                ['Cancelled', $now['cancelled'], $before['cancelled'], 'bg-gradient-to-br from-rose-600 to-red-500'],
            ] as [$label, $a, $b, $bg])
                @php [$txt] = $delta($a, $b); @endphp
                <div class="{{ $tile }} {{ $bg }}">
                    <div class="text-xs opacity-90">{{ $label }}</div>
                    <div class="text-3xl font-extrabold">{{ number_format($a) }}</div>
                    <div class="text-xs opacity-90">{{ $txt !== '' ? $txt.' vs last month' : 'nothing last month' }}</div>
                </div>
            @endforeach
            <div class="{{ $tile }} bg-gradient-to-br from-amber-500 to-orange-500">
                <div class="text-xs opacity-90">Average job value</div>
                <div class="text-3xl font-extrabold">₹{{ number_format($now['avg']) }}</div>
                <div class="text-xs opacity-90">{{ $now['rate'] !== null ? $now['rate'].'% of bookings completed' : 'no bookings yet' }}</div>
            </div>
        </div>
    @endif

    @if ($showCustomers)
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-insights-customers>
            @foreach ([['Active customers', $now['customers'], $before['customers']], ['New customers', $now['new'], $before['new']], ['Repeat customers', $now['repeat'], $before['repeat']], ['Partners who completed jobs', $now['partners'], $before['partners']]] as [$label, $a, $b])
                @php [$txt, $cls] = $delta($a, $b); @endphp
                <x-ui.card>
                    <div class="text-xs text-gray-500 mb-1">{{ $label }}</div>
                    <div class="text-2xl font-semibold">{{ number_format($a) }}</div>
                    @if ($txt !== '')<div class="text-xs {{ $cls }}">{{ $txt }} vs last month</div>@endif
                </x-ui.card>
            @endforeach
        </div>
    @endif

    @if ($showServices)
        <div class="grid lg:grid-cols-2 gap-4 mb-4" data-insights-services>
            <x-ui.card>
                <h2 class="text-sm font-semibold mb-3">Top services this month</h2>
                @forelse ($top as $t)
                    <div class="mb-2">
                        <div class="flex justify-between text-sm"><span>{{ $t['name'] }}</span><span class="font-mono">{{ $t['n'] }}</span></div>
                        <div class="h-2 rounded bg-gray-100"><div class="h-2 rounded bg-blue-500" style="width: {{ round(100 * $t['n'] / max(1, $top->first()['n'])) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No bookings this month.</p>
                @endforelse
            </x-ui.card>
            <x-ui.card>
                <h2 class="text-sm font-semibold mb-3">Bookings, last 14 days</h2>
                <div class="flex items-end gap-1 h-28">
                    @foreach ($trend as $d)
                        <div class="flex-1 flex flex-col justify-end items-center" title="{{ $d['day']->format('d M') }}: {{ $d['n'] }}">
                            <div class="w-full rounded-t bg-emerald-500" style="height: {{ round(100 * $d['n'] / $trendMax) }}%; min-height: {{ $d['n'] > 0 ? '4px' : '0' }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between text-[10px] text-gray-400 mt-1"><span>{{ $trend->first()['day']->format('d M') }}</span><span>{{ $trend->last()['day']->format('d M') }}</span></div>
            </x-ui.card>
        </div>
    @endif

    <p class="text-xs text-gray-500">Money earned is on <a class="underline" href="{{ route('admin.franchise-earnings.index', ['franchiseId' => $franchise->id, 'month' => $month]) }}">Franchise Earnings</a>.</p>
</div>
