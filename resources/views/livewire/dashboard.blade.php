<div>
    <h1 class="text-2xl font-bold mb-6">Dashboard</h1>

    <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-semibold text-gray-500">Pipeline this {{ $period }}</div>
        <div class="flex gap-1 bg-white rounded p-1 shadow-sm">
            @foreach (['week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'] as $key => $label)
                <button wire:click="setPeriod('{{ $key }}')"
                        class="px-3 py-1 rounded text-xs font-medium {{ $period === $key ? 'bg-slate-900 text-white' : 'text-gray-500 hover:bg-gray-100' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{--
        x-ui.card is always a plain <div> (no tag/href prop) -- cards that
        should be clickable are wrapped in a real <a> here rather than
        trying to make the shared component polymorphic for one screen.
        Each pipeline stage links to Bookings pre-filtered to that status
        (Bookings\Index::$statusFilter); "Assigned" and "In Progress" cards
        each count two statuses but $statusFilter takes one, so they land on
        the primary status of the pair.
    --}}
    @php($linkOrDiv = fn ($href) => $href ? 'a' : 'div')
    @php($pipelineCards = [
        ['label' => 'Searching', 'value' => $funnel['searching'], 'colour' => 'text-blue-600', 'href' => $links['bookings_searching']],
        ['label' => 'Assigned', 'value' => $funnel['assigned'], 'colour' => 'text-indigo-600', 'href' => $links['bookings_assigned']],
        ['label' => 'In Progress', 'value' => $funnel['in_progress'], 'colour' => 'text-amber-600', 'href' => $links['bookings_in_progress']],
        ['label' => 'Completed', 'value' => $funnel['completed'], 'colour' => 'text-green-600', 'href' => $links['bookings_completed']],
        ['label' => 'Cancelled', 'value' => $funnel['cancelled'], 'colour' => 'text-red-500', 'href' => $links['bookings_cancelled']],
        ['label' => 'Disputed', 'value' => $funnel['disputed'], 'colour' => 'text-red-700', 'href' => $links['bookings_disputed']],
    ])
    <div class="grid grid-cols-3 md:grid-cols-6 gap-3 mb-8">
        @foreach ($pipelineCards as $c)
            <{{ $linkOrDiv($c['href']) }} @if($c['href']) href="{{ $c['href'] }}" @endif class="block">
                <x-ui.card class="!p-3 text-center {{ $c['href'] ? 'hover:shadow-md transition' : '' }}">
                    <div class="text-xs text-gray-500">{{ $c['label'] }}</div>
                    <div class="text-xl font-bold {{ $c['colour'] }}">{{ $c['value'] }}</div>
                </x-ui.card>
            </{{ $linkOrDiv($c['href']) }}>
        @endforeach
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <{{ $linkOrDiv($links['bookings']) }} @if($links['bookings']) href="{{ $links['bookings'] }}" @endif class="block">
            <x-ui.card class="{{ $links['bookings'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Bookings Today</div>
                <div class="text-2xl font-bold">{{ $stats['bookings_today'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['bookings']) }}>
        <{{ $linkOrDiv($links['bookings']) }} @if($links['bookings']) href="{{ $links['bookings'] }}" @endif class="block">
            <x-ui.card class="{{ $links['bookings'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Active Now</div>
                <div class="text-2xl font-bold text-amber-600">{{ $stats['active_bookings'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['bookings']) }}>
        <{{ $linkOrDiv($links['bookings_completed']) }} @if($links['bookings_completed']) href="{{ $links['bookings_completed'] }}" @endif class="block">
            <x-ui.card class="{{ $links['bookings_completed'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Completed Today</div>
                <div class="text-2xl font-bold text-green-600">{{ $stats['completed_today'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['bookings_completed']) }}>
        {{-- Revenue Today drills into Commissions filtered to today — the
             per-booking provider/franchise/platform split behind the number
             ("who got paid for today's revenue"). Bookings\Index has no
             date query-string, so there is no today-boxed Bookings view to
             link instead. --}}
        <{{ $linkOrDiv($links['commissions_today']) }} @if($links['commissions_today']) href="{{ $links['commissions_today'] }}" @endif class="block">
            <x-ui.card class="{{ $links['commissions_today'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Revenue Today</div>
                <div class="text-2xl font-bold">{{ $currencySymbol }}{{ number_format($stats['revenue_today'], 2) }}</div>
                @if ($links['commissions_today'])
                    <div class="mt-0.5 text-[11px] text-gray-400">View today's commissions →</div>
                @endif
            </x-ui.card>
        </{{ $linkOrDiv($links['commissions_today']) }}>
        {{-- Completion Rate is a ratio, not a record set — nothing to drill
             into, so it stays an unlinked KPI card (flagged, not forced). --}}
        <x-ui.card>
            <div class="text-sm text-gray-500">Completion Rate ({{ $period }})</div>
            <div class="text-2xl font-bold {{ $stats['completion_rate'] === null ? 'text-gray-400' : ($stats['completion_rate'] >= 90 ? 'text-green-600' : 'text-amber-600') }}">
                {{ $stats['completion_rate'] === null ? '—' : $stats['completion_rate'].'%' }}
            </div>
        </x-ui.card>
        <{{ $linkOrDiv($links['providers_online']) }} @if($links['providers_online']) href="{{ $links['providers_online'] }}" @endif class="block">
            <x-ui.card class="{{ $links['providers_online'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Providers Online</div>
                <div class="text-2xl font-bold">{{ $stats['providers_online'] }} <span class="text-sm text-gray-400 font-normal">/ {{ $stats['providers_total'] }}</span></div>
            </x-ui.card>
        </{{ $linkOrDiv($links['providers_online']) }}>
        <{{ $linkOrDiv($links['franchises_active']) }} @if($links['franchises_active']) href="{{ $links['franchises_active'] }}" @endif class="block">
            <x-ui.card class="{{ $links['franchises_active'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Active Franchises</div>
                <div class="text-2xl font-bold">{{ $stats['franchises_active'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['franchises_active']) }}>
        {{-- Mission's own explicit "Active Operations" priority signal -- the
             current, un-time-boxed backlog, not filtered to the selected period. --}}
        <{{ $linkOrDiv($links['bookings_searching']) }} @if($links['bookings_searching']) href="{{ $links['bookings_searching'] }}" @endif class="block">
            <x-ui.card class="{{ ($links['bookings_searching'] ? 'hover:shadow-md transition ' : '').($stats['unassigned_bookings'] > 0 ? 'ring-1 ring-red-200' : '') }}">
                <div class="text-sm text-gray-500">Unassigned Bookings</div>
                <div class="text-2xl font-bold {{ $stats['unassigned_bookings'] > 0 ? 'text-red-600' : '' }}">{{ $stats['unassigned_bookings'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['bookings_searching']) }}>
        {{-- Commissions was only reachable via the collapsed Finance nav
             group — a direct card here makes "who got paid" one click from
             the dashboard. The amber note fires when every active franchise
             in scope is still on the zero commission default (Part A):
             CommissionService is then splitting 100% to providers and
             booking nothing for platform/franchise, so the Commissions
             screen's numbers are 100/0/0 by omission, not decision. --}}
        @if ($links['commissions'])
            <a href="{{ $links['commissions'] }}" class="block">
                <x-ui.card class="hover:shadow-md transition {{ $commissionRatesConfigured ? '' : 'ring-1 ring-amber-200' }}">
                    <div class="text-sm text-gray-500">Commissions</div>
                    <div class="text-2xl font-bold">View splits →</div>
                    @unless ($commissionRatesConfigured)
                        <div class="mt-0.5 text-[11px] font-medium text-amber-600">Rates not set — 100% to providers</div>
                    @endunless
                </x-ui.card>
            </a>
        @endif
    </div>

    {{-- Admin Polish + AI session, Part 1 item 1 — a real 7-day trend
         (Dashboard::sevenDayTrend()), not a decorative element. Plain
         server-rendered bars (bar height = real per-day value) rather than
         an SVG polyline or a JS charting library — no Node/npm toolchain
         is available in this environment to build one in (see
         KNOWN_RISKS_AND_DECISIONS.md), and bars need no JS at all to be a
         genuine chart. aria-label carries the same data as a sentence for
         screen readers; the bars themselves are aria-hidden. --}}
    @php($maxBookings = max(1, collect($trend)->max('bookings')))
    @php($maxRevenue = max(1, collect($trend)->max('revenue')))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <x-ui.card>
            <div class="text-sm font-semibold text-gray-500 uppercase mb-4">Bookings — Last 7 Days</div>
            <div class="flex items-end gap-2 h-28" role="img"
                 aria-label="Bookings per day: {{ collect($trend)->map(fn ($d) => $d['label'].' '.$d['bookings'])->implode(', ') }}">
                @foreach ($trend as $day)
                    <div class="flex-1 flex flex-col items-center gap-1">
                        <div class="text-[11px] font-medium text-gray-600">{{ $day['bookings'] }}</div>
                        <div class="w-full bg-indigo-500 rounded-t" aria-hidden="true"
                             style="height: {{ max(3, (int) round(($day['bookings'] / $maxBookings) * 72)) }}px"></div>
                        <div class="text-[11px] text-gray-400">{{ $day['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-semibold text-gray-500 uppercase mb-4">Revenue — Last 7 Days</div>
            <div class="flex items-end gap-2 h-28" role="img"
                 aria-label="Revenue per day: {{ collect($trend)->map(fn ($d) => $d['label'].' '.$currencySymbol.number_format($d['revenue'], 0))->implode(', ') }}">
                @foreach ($trend as $day)
                    <div class="flex-1 flex flex-col items-center gap-1">
                        <div class="text-[11px] font-medium text-gray-600">{{ number_format($day['revenue'], 0) }}</div>
                        <div class="w-full bg-green-500 rounded-t" aria-hidden="true"
                             style="height: {{ max(3, (int) round(($day['revenue'] / $maxRevenue) * 72)) }}px"></div>
                        <div class="text-[11px] text-gray-400">{{ $day['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    {{-- Admin Polish + AI session, Part 2 item 3 — Daily Insights. Only the
         facts App\Services\Operations\OperationalInsightsService actually
         computed are ever shown; 'summary' is either the AI-phrased
         paragraph (see NarrativeAiAdapter) or null, in which case the
         plain fact list below renders directly — the panel is fully
         correct with AI on OR off. Absent entirely for a viewer without
         operations.view (see Dashboard::render()'s own comment). --}}
    @if ($insights !== null)
        <x-ui.card class="mb-8">
            <div class="flex items-center gap-2 mb-3">
                <x-icon name="sparkles" class="w-4 h-4 text-indigo-500" />
                <div class="text-sm font-semibold text-gray-500 uppercase">Daily Insights</div>
            </div>
            @if (empty($insights['facts']))
                <x-ui.empty-state icon="check-circle" title="Nothing needs attention right now" description="No stuck bookings, provider anomalies, or zone coverage gaps detected." />
            @elseif ($insights['summary'])
                <p class="text-sm text-gray-700 leading-relaxed">{{ $insights['summary'] }}</p>
            @else
                <ul class="text-sm text-gray-700 space-y-1.5 list-disc list-inside">
                    @foreach ($insights['facts'] as $fact)
                        <li>{{ $fact }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    @if (!empty($otherVerticals))
        {{-- Admin Command Center mission (Phase 4) -- Parcel/Taxi/Rental/Hotel/
             Marketplace volume, invisible on this screen before this session
             even after each vertical shipped a real admin screen. Only shows
             verticals the viewer actually holds permission to open. --}}
        <div class="text-sm font-semibold text-gray-500 mb-3">Other Verticals — Today</div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-8">
            @foreach ($otherVerticals as $v)
                <a href="{{ $v['route'] }}" class="block">
                    <x-ui.card class="hover:shadow-md transition">
                        <div class="text-sm text-gray-500">{{ $v['label'] }}</div>
                        <div class="text-2xl font-bold">{{ $v['count'] }}</div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>
    @endif

    <x-ui.table>
        <x-slot:header>Recent Bookings</x-slot:header>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Code</th>
                <th class="px-4 py-2">Customer</th>
                <th class="px-4 py-2">Service</th>
                <th class="px-4 py-2">Provider</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Price</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($recentBookings as $booking)
                <tr class="border-t">
                    <td class="px-4 py-2 font-mono text-xs">{{ $booking->code }}</td>
                    <td class="px-4 py-2">{{ $booking->customer->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $booking->service->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $booking->provider?->user?->name ?? '— unassigned —' }}</td>
                    <td class="px-4 py-2">
                        <x-ui.status-badge type="booking" :status="$booking->status" />
                    </td>
                    <td class="px-4 py-2">{{ $currencySymbol }}{{ number_format($booking->price_final ?? $booking->price_quoted, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state icon="clipboard" title="No bookings yet" /></td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
