<div>
    {{-- Owner 2026-11-09: a pleasant welcome with today's date. --}}
    <div class="relative mb-6 flex flex-wrap items-center justify-between gap-4 overflow-hidden rounded-2xl bg-gradient-to-r from-indigo-600 via-blue-600 to-sky-500 px-6 py-5 text-white shadow-md">
        <div aria-hidden="true" class="pointer-events-none absolute -right-10 -top-12 h-40 w-40 rounded-full bg-white/10"></div>
        <div aria-hidden="true" class="pointer-events-none absolute right-24 -bottom-16 h-32 w-32 rounded-full bg-white/10"></div>
        <div class="relative">
            <p class="text-sm font-medium text-white/80">{{ $greeting['hour'] < 12 ? 'Good morning' : ($greeting['hour'] < 17 ? 'Good afternoon' : 'Good evening') }}, {{ $greeting['name'] }}</p>
            <h1 class="text-2xl font-bold">Dashboard</h1>
            <p class="mt-0.5 text-sm text-white/90" data-testid="dashboard-date">{{ $greeting['date'] }}</p>
        </div>

        {{-- Phase 2 — "Enable order alerts": opt this admin into a push on
             every new booking / captured payment (App\Services\AdminOpsAlertService,
             gated on users.push_ops_alerts). All client-side via
             window.adminPush (public/js/admin-push.js, loaded in the admin
             layout); silently absent when Firebase env isn't configured. --}}
        <div x-data="{
                supported: false,
                enabled: @js((bool) auth()->user()->push_ops_alerts),
                busy: false,
                msg: '',
                init() { this.supported = !!(window.adminPush && window.adminPush.isSupported()); },
                async toggle() {
                    if (!window.adminPush) return;
                    this.busy = true; this.msg = '';
                    const res = this.enabled ? await window.adminPush.disable() : await window.adminPush.enable();
                    this.busy = false;
                    if (res && res.ok) { this.enabled = !this.enabled; }
                    else { this.msg = 'Could not update. Check notification permission for this site.'; }
                }
             }"
             x-show="supported || enabled" style="display:none" class="relative text-right">
            <button type="button" x-on:click="toggle()" x-bind:disabled="busy"
                    class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition disabled:opacity-60"
                    x-bind:class="enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-white/40 bg-white/15 text-white hover:bg-white/25'">
                <span x-show="!busy" x-text="enabled ? 'Order alerts on' : 'Enable order alerts'"></span>
                <span x-show="busy">Working…</span>
            </button>
            <p x-show="msg" x-text="msg" class="mt-1 text-xs text-white"></p>
        </div>
    </div>

    {{-- Module tabs (owner request 2026-11-03): one colourful tab per module the viewer can open, Flipkart-style.
         "All modules" is the whole business; each module tab shows its own numbers and its screens & settings. --}}
    @php($tabIcons = ['all' => 'home', 'service' => 'wrench', 'parcel' => 'shopping-bag', 'shop' => 'shopping-cart', 'food' => 'sparkles', 'taxi' => 'map-pin', 'rental' => 'building', 'hotel' => 'building'])
    <div class="mb-5 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Dashboard modules">
        @foreach ($tabs as $key => $t)
            @php($tone = \App\Support\AdminNav::tone($t['colour']))
            <button type="button" role="tab" wire:click="setTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'inline-flex shrink-0 items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold shadow-sm transition',
                        $tone['solid'].' border-transparent shadow-md' => $tab === $key,
                        $tone['chip'].' hover:shadow' => $tab !== $key,
                    ])>
                <x-icon :name="$tabIcons[$key] ?? 'home'" class="h-4 w-4" />
                {{ $t['label'] }}
                @if ($t['prelaunch'])
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $tab === $key ? 'bg-white/20' : 'bg-white/70' }}">soon</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div class="text-sm font-semibold text-gray-500">{{ $tabs[$tab]['label'] }} · {{ $periodLabel }} · <span class="text-gray-700" data-testid="period-dates">{{ $periodDates }}</span></div>
        <div class="flex gap-1 rounded bg-white p-1 shadow-sm">
            @foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'] as $key => $label)
                <button wire:click="setPeriod('{{ $key }}')"
                        class="px-3 py-1 rounded text-xs font-medium {{ $period === $key ? 'bg-slate-900 text-white' : 'text-gray-500 hover:bg-gray-100' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($tab === 'all')
        {{-- The whole business: every module's orders and sales, one grand total. --}}
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['label' => 'Total orders', 'value' => number_format($salesTotal['orders']), 'hint' => $salesTotal['open'].' open · '.$salesTotal['cancelled'].' cancelled', 'bg' => 'from-indigo-500 to-blue-600', 'url' => $kpiLinks['orders']],
                ['label' => 'Total sales', 'value' => $currencySymbol.number_format($salesTotal['sales'], 2), 'hint' => $salesTotal['completed'].' completed orders', 'bg' => 'from-emerald-500 to-green-600', 'url' => $kpiLinks['sales']],
                ['label' => 'Paid online', 'value' => $currencySymbol.number_format($salesTotal['online'], 2), 'hint' => 'Razorpay and wallet', 'bg' => 'from-violet-500 to-purple-600', 'url' => $kpiLinks['online']],
                ['label' => 'Cash', 'value' => $currencySymbol.number_format($salesTotal['cash'], 2), 'hint' => 'collected by partners', 'bg' => 'from-orange-400 to-amber-500', 'url' => $kpiLinks['cash']],
            ] as $k)
                <a href="{{ $k['url'] }}" @unless (str_starts_with($k['url'], '#')) wire:navigate @endunless
                   class="group block rounded-2xl bg-gradient-to-br {{ $k['bg'] }} p-5 text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg">
                    <div class="flex items-center justify-between text-xs font-medium uppercase tracking-wide text-white/80">{{ $k['label'] }} <span aria-hidden="true" class="opacity-0 transition group-hover:opacity-100">→</span></div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $k['value'] }}</div>
                    <div class="mt-0.5 text-xs text-white/80">{{ $k['hint'] }}</div>
                </a>
            @endforeach
        </div>

        {{-- Who is working right now; each tile opens its list. --}}
        @if (! empty($team))
            <div class="mb-6 grid grid-cols-2 gap-3 {{ [1 => 'sm:grid-cols-1', 2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-3', 4 => 'sm:grid-cols-2 lg:grid-cols-4', 5 => 'sm:grid-cols-3 lg:grid-cols-5'][count($team)] ?? 'sm:grid-cols-3 lg:grid-cols-6' }}" data-testid="team-now">
                @foreach ($team as $t)
                    @php($tone = \App\Support\AdminNav::tone($t['colour']))
                    @php($pct = $t['of'] > 0 ? (int) round($t['value'] / $t['of'] * 100) : 0)
                    <a href="{{ $t['url'] }}" wire:navigate class="group block">
                        <x-ui.card class="h-full transition group-hover:shadow-md">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border {{ $tone['chip'] }}"><x-icon :name="$t['icon']" class="h-5 w-5" /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-xs font-medium text-gray-500">{{ $t['label'] }}</div>
                                    <div class="text-xl font-bold tabular-nums">{{ $t['value'] }} <span class="text-sm font-normal text-gray-400">/ {{ $t['of'] }}</span></div>
                                </div>
                                @if ($t['value'] > 0)<span class="relative flex h-2.5 w-2.5" aria-hidden="true"><span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>@endif
                            </div>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full {{ $tone['bar'] }}" style="width: {{ $pct }}%"></div></div>
                        </x-ui.card>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Owner 2026-11-09: a circle and a bar chart. Sales share by module (orders when nothing finished yet) and
             orders per day for the last 7 days, one colour per module. Tap a module to open its tab. --}}
        <?php
            $hex = ['blue' => '#3b82f6', 'orange' => '#f97316', 'violet' => '#8b5cf6', 'rose' => '#f43f5e', 'amber' => '#f59e0b', 'teal' => '#14b8a6', 'cyan' => '#06b6d4', 'fuchsia' => '#d946ef', 'slate' => '#64748b'];
            $shareBy = $salesTotal['sales'] > 0 ? 'sales' : 'orders';
            $shareTotal = max(0.0001, (float) collect($moduleSales)->sum($shareBy));
            $stops = []; $at = 0.0;
            foreach ($moduleSales as $m) {
                $part = (float) $m[$shareBy] / $shareTotal * 100;
                if ($part <= 0) { continue; }
                $stops[] = ($hex[$m['colour']] ?? '#64748b').' '.round($at, 2).'% '.round($at + $part, 2).'%';
                $at += $part;
            }
            $ring = $stops ? 'conic-gradient('.implode(', ', $stops).')' : 'conic-gradient(#e5e7eb 0 100%)';
            $dayMax = max(1, collect($daily)->map(fn ($d) => array_sum($d))->max());
        ?>
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
            <x-ui.card class="lg:col-span-2">
                <div class="mb-4 text-sm font-semibold uppercase text-gray-500">{{ $shareBy === 'sales' ? 'Sales' : 'Orders' }} by module · {{ $periodLabel }}</div>
                <div class="flex flex-wrap items-center gap-6">
                    <div class="relative h-36 w-36 shrink-0 rounded-full" style="background: {{ $ring }}" role="img" data-testid="module-donut"
                         aria-label="{{ collect($moduleSales)->map(fn ($m) => $m['label'].' '.($shareBy === 'sales' ? $currencySymbol.number_format($m['sales'], 0) : $m['orders']))->implode(', ') }}">
                        <div class="absolute inset-4 flex flex-col items-center justify-center rounded-full bg-white text-center">
                            <span class="text-lg font-bold tabular-nums">{{ $shareBy === 'sales' ? $currencySymbol.number_format($salesTotal['sales'], 0) : $salesTotal['orders'] }}</span>
                            <span class="text-[11px] text-gray-500">{{ $shareBy === 'sales' ? 'sales' : 'orders' }}</span>
                        </div>
                    </div>
                    <ul class="min-w-0 flex-1 space-y-1.5 text-sm">
                        @foreach ($moduleSales as $key => $m)
                            <li>
                                <button type="button" wire:click="setTab('{{ $key }}')" class="flex w-full items-center justify-between gap-2 rounded-md px-1 py-0.5 hover:bg-gray-50">
                                    <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $hex[$m['colour']] ?? '#64748b' }}"></span>{{ $m['label'] }}</span>
                                    <span class="tabular-nums text-gray-600">{{ $shareBy === 'sales' ? $currencySymbol.number_format($m['sales'], 0) : $m['orders'] }} <span class="text-xs text-gray-400">{{ (int) round((float) $m[$shareBy] / $shareTotal * 100) }}%</span></span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </x-ui.card>
            <x-ui.card class="lg:col-span-3">
                <div class="mb-4 text-sm font-semibold uppercase text-gray-500">Orders · last 7 days</div>
                <div class="flex h-40 items-end gap-2" role="img" data-testid="daily-bars"
                     aria-label="Orders per day: {{ collect($daily)->map(fn ($d, $date) => \Illuminate\Support\Carbon::parse($date)->format('D').' '.array_sum($d))->implode(', ') }}">
                    @foreach ($daily as $date => $perModule)
                        @php($sum = array_sum($perModule))
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ \Illuminate\Support\Carbon::parse($date)->format('D j M') }}: {{ $sum }} orders">
                            <div class="text-[11px] font-medium text-gray-600">{{ $sum }}</div>
                            <div class="flex w-full flex-col-reverse overflow-hidden rounded-t" style="height: {{ max(3, (int) round($sum / $dayMax * 112)) }}px">
                                @if ($sum === 0)
                                    <div class="h-full w-full bg-gray-200"></div>
                                @else
                                    @foreach ($perModule as $key => $n)
                                        @if ($n > 0)<div style="height: {{ $n / $sum * 100 }}%; background: {{ $hex[$moduleSales[$key]['colour'] ?? 'slate'] ?? '#64748b' }}"></div>@endif
                                    @endforeach
                                @endif
                            </div>
                            <div @class(['text-[11px]', 'font-semibold text-gray-700' => $loop->last, 'text-gray-400' => ! $loop->last])>{{ $loop->last ? 'Today' : \Illuminate\Support\Carbon::parse($date)->format('D') }}</div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($moduleSales as $key => $m)
                @php($tone = \App\Support\AdminNav::tone($m['colour']))
                <button type="button" wire:click="setTab('{{ $key }}')" class="group block text-left">
                    <x-ui.card class="h-full overflow-hidden !p-0 transition group-hover:shadow-md">
                        <div class="h-1.5 {{ $tone['bar'] }}"></div>
                        <div class="p-4">
                            <div class="flex items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-lg border px-2 py-0.5 text-xs font-semibold {{ $tone['chip'] }}">
                                    <x-icon :name="$tabIcons[$key] ?? 'home'" class="h-3.5 w-3.5" /> {{ $m['label'] }}
                                </span>
                                @if ($m['prelaunch'])<span class="text-[10px] font-medium text-amber-600">pre-launch</span>@endif
                            </div>
                            <div class="mt-3 text-xl font-bold tabular-nums">{{ $currencySymbol }}{{ number_format($m['sales'], 2) }}</div>
                            <div class="text-xs text-gray-500">{{ $m['orders'] }} orders · {{ $m['completed'] }} done</div>
                        </div>
                    </x-ui.card>
                </button>
            @endforeach
        </div>

        <div id="sales-by-module" class="scroll-mt-24"></div>
        <x-ui.table>
            <x-slot:header>Sales by module · {{ $periodLabel }} · {{ $periodDates }}</x-slot:header>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Module</th>
                    <th class="px-4 py-2 text-right">Orders</th>
                    <th class="px-4 py-2 text-right">Open</th>
                    <th class="px-4 py-2 text-right">Completed</th>
                    <th class="px-4 py-2 text-right">Cancelled</th>
                    <th class="px-4 py-2 text-right">Online</th>
                    <th class="px-4 py-2 text-right">Cash</th>
                    <th class="px-4 py-2 text-right">Sales</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($moduleSales as $key => $m)
                    @php($tone = \App\Support\AdminNav::tone($m['colour']))
                    <tr class="border-t">
                        <td class="px-4 py-2">
                            <button type="button" wire:click="setTab('{{ $key }}')" class="inline-flex items-center gap-2 font-medium hover:text-blue-700 hover:underline"><span class="h-2.5 w-2.5 rounded-full {{ $tone['bar'] }}"></span>{{ $m['label'] }}</button>
                            @if ($m['prelaunch'])<span class="ml-1 text-[11px] text-amber-600">pre-launch</span>@endif
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $m['orders'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-amber-600">{{ $m['open'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-green-600">{{ $m['completed'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-red-500">{{ $m['cancelled'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $currencySymbol }}{{ number_format($m['online'], 2) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $currencySymbol }}{{ number_format($m['cash'], 2) }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $currencySymbol }}{{ number_format($m['sales'], 2) }}</td>
                        <td class="px-4 py-2 text-right">@if ($m['route'])<a href="{{ $m['route'] }}" class="text-xs font-medium text-blue-600 hover:underline">Orders →</a>@endif</td>
                    </tr>
                @endforeach
                <tr class="border-t-2 bg-gray-50 font-semibold">
                    <td class="px-4 py-2">Total</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $salesTotal['orders'] }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $salesTotal['open'] }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $salesTotal['completed'] }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $salesTotal['cancelled'] }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $currencySymbol }}{{ number_format($salesTotal['online'], 2) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $currencySymbol }}{{ number_format($salesTotal['cash'], 2) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $currencySymbol }}{{ number_format($salesTotal['sales'], 2) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </x-ui.table>
        <p class="mt-2 mb-8 text-xs text-gray-400">Orders placed in the period. Sales = orders that finished (completed, delivered or checked out). Online = Razorpay and wallet.</p>

        {{-- Admin Polish + AI session, Part 2 item 3 — Daily Insights. Only the
             facts App\Services\Operations\OperationalInsightsService actually
             computed are ever shown; 'summary' is either the AI-phrased
             paragraph (see NarrativeAiAdapter) or null, in which case the
             plain fact list below renders directly. Absent entirely for a
             viewer without operations.view. --}}
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
    @else
        {{-- One module: its own numbers for the period, then its screens and settings. --}}
        @php($tone = \App\Support\AdminNav::tone($row['colour']))
        @if ($row['prelaunch'])
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ $row['label'] }} is pre-launch. Switch it on in <b>Settings &amp; System → Modules (on / off)</b> when it is ready.
            </div>
        @endif
        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['label' => 'Orders', 'value' => $row['orders'], 'tone' => 'text-gray-900'],
                ['label' => 'Open', 'value' => $row['open'], 'tone' => 'text-amber-600'],
                ['label' => 'Completed', 'value' => $row['completed'], 'tone' => 'text-green-600'],
                ['label' => 'Cancelled', 'value' => $row['cancelled'], 'tone' => 'text-red-600'],
                ['label' => 'Sales', 'value' => $currencySymbol.number_format($row['sales'], 2), 'tone' => 'text-gray-900'],
                ['label' => 'Online / Cash', 'value' => number_format($row['online'], 0).' / '.number_format($row['cash'], 0), 'tone' => 'text-blue-600'],
            ] as $card)
                <{{ $row['route'] ? 'a' : 'div' }} @if ($row['route']) href="{{ $row['route'] }}" @endif class="block">
                    <x-ui.card class="h-full overflow-hidden !p-0 transition hover:shadow-md">
                        <div class="h-1 {{ $tone['bar'] }}"></div>
                        <div class="p-4">
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                            <div class="mt-1 text-2xl font-bold tabular-nums {{ $card['tone'] }}">{{ $card['value'] }}</div>
                        </div>
                    </x-ui.card>
                </{{ $row['route'] ? 'a' : 'div' }}>
            @endforeach
        </div>

        @if (! empty($quickLinks))
            <x-ui.card class="mb-6">
                <div class="mb-3 text-sm font-semibold uppercase text-gray-500">{{ $row['label'] }} — screens &amp; settings</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($quickLinks as $link)
                        <a href="{{ $link['url'] }}" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition hover:shadow {{ $tone['chip'] }}">
                            <x-icon :name="$link['icon']" class="h-4 w-4" /> {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        @if ($tab === 'service')
            {{--
                x-ui.card is always a plain <div> (no tag/href prop) -- cards that
                should be clickable are wrapped in a real <a> here. Each pipeline
                stage links to Bookings pre-filtered to that status
                (Bookings\Index::$statusFilter); "Assigned" and "In Progress" cards
                each count two statuses but $statusFilter takes one, so they land on
                the primary status of the pair.
            --}}
            <div class="text-sm font-semibold text-gray-500 mb-3">Pipeline · {{ $periodLabel }}</div>
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
                     per-booking provider/franchise/platform split behind the number. --}}
                <{{ $linkOrDiv($links['commissions_today']) }} @if($links['commissions_today']) href="{{ $links['commissions_today'] }}" @endif class="block">
                    <x-ui.card class="{{ $links['commissions_today'] ? 'hover:shadow-md transition' : '' }}">
                        <div class="text-sm text-gray-500">Revenue Today</div>
                        <div class="text-2xl font-bold">{{ $currencySymbol }}{{ number_format($stats['revenue_today'], 2) }}</div>
                        @if ($links['commissions_today'])
                            <div class="mt-0.5 text-[11px] text-gray-400">View today's commissions →</div>
                        @endif
                    </x-ui.card>
                </{{ $linkOrDiv($links['commissions_today']) }}>
                {{-- Completion Rate is a ratio, not a record set — nothing to drill into. --}}
                <x-ui.card>
                    <div class="text-sm text-gray-500">Completion Rate ({{ $periodLabel }})</div>
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
                {{-- The current, un-time-boxed backlog, not filtered to the selected period. --}}
                <{{ $linkOrDiv($links['bookings_searching']) }} @if($links['bookings_searching']) href="{{ $links['bookings_searching'] }}" @endif class="block">
                    <x-ui.card class="{{ ($links['bookings_searching'] ? 'hover:shadow-md transition ' : '').($stats['unassigned_bookings'] > 0 ? 'ring-1 ring-red-200' : '') }}">
                        <div class="text-sm text-gray-500">Unassigned Bookings</div>
                        <div class="text-2xl font-bold {{ $stats['unassigned_bookings'] > 0 ? 'text-red-600' : '' }}">{{ $stats['unassigned_bookings'] }}</div>
                    </x-ui.card>
                </{{ $linkOrDiv($links['bookings_searching']) }}>
                {{-- The amber note fires when every active franchise in scope is still
                     on the zero commission default: the Commissions screen's numbers
                     are then 100/0/0 by omission, not decision. --}}
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

            {{-- A real 7-day trend (Dashboard::sevenDayTrend()): plain server-rendered bars,
                 bar height = real per-day value. aria-label carries the same data as a sentence. --}}
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

            <x-ui.table>
                <x-slot:header>Recent Bookings</x-slot:header>

                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <x-ui.sno-th />
                        <th class="px-4 py-2">Code</th>
                        <th class="px-4 py-2">Customer</th>
                        <th class="px-4 py-2">Service</th>
                        <th class="px-4 py-2">Provider</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Price</th>
                        <x-ui.tat-heads />
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentBookings as $booking)
                        <tr class="border-t">
                            <x-ui.sno :rows="$recentBookings" :loop="$loop" />
                            <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('admin.bookings.show', $booking->id) }}" wire:navigate class="text-blue-700 hover:underline">{{ $booking->code }}</a></td>
                            <td class="px-4 py-2">{{ $booking->customer->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $booking->service->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $booking->provider?->user?->name ?? '— unassigned —' }}</td>
                            <td class="px-4 py-2">
                                <x-ui.status-badge type="booking" :status="$booking->status" />
                            </td>
                            <td class="px-4 py-2">{{ $currencySymbol }}{{ number_format($booking->price_final ?? $booking->price_quoted, 2) }}</td>
                            <x-ui.tat-cells :record="$booking" done="completed_at"/>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-ui.empty-state icon="clipboard" title="No bookings yet" /></td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        @else
            @if (! empty($parcelToday))
                {{-- Parcel — Today (owner 2026-10-28), same permission and scope as the Parcel screens. --}}
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-orange-100 text-orange-600"><x-icon name="shopping-bag" class="h-4 w-4" /></span>
                        Parcel — Today
                    </div>
                    <a href="{{ $parcelToday['dashboard'] }}" class="text-xs font-medium text-blue-600 hover:underline">Open Parcel Dashboard →</a>
                </div>
                <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                    @foreach ([
                        ['label' => 'Orders', 'value' => $parcelToday['orders'], 'hint' => 'placed today', 'tone' => 'text-gray-900'],
                        ['label' => 'Live now', 'value' => $parcelToday['live'], 'hint' => 'searching or on the way', 'tone' => 'text-amber-600'],
                        ['label' => 'Delivered', 'value' => $parcelToday['delivered'], 'hint' => 'completed today', 'tone' => 'text-green-600'],
                        ['label' => 'Cancelled', 'value' => $parcelToday['cancelled'], 'hint' => 'today', 'tone' => 'text-red-600'],
                        ['label' => 'Revenue', 'value' => $currencySymbol.number_format($parcelToday['revenue'], 2), 'hint' => 'delivered orders', 'tone' => 'text-gray-900'],
                        ['label' => 'Avg delivery TAT', 'value' => \App\Support\Turnaround::format($parcelToday['avg_tat']), 'hint' => 'order to drop-off', 'tone' => 'text-blue-600'],
                    ] as $card)
                        <a href="{{ $parcelToday['route'] }}" class="block">
                            <x-ui.card class="h-full transition hover:shadow-md">
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                                <div class="mt-1 text-2xl font-bold tabular-nums {{ $card['tone'] }}">{{ $card['value'] }}</div>
                                <div class="text-xs text-gray-400">{{ $card['hint'] }}</div>
                            </x-ui.card>
                        </a>
                    @endforeach
                </div>
            @endif

            @if (in_array($tab, ['parcel', 'shop', 'food'], true))
                <x-ui.table>
                    <x-slot:header>Recent {{ $row['label'] }} Orders</x-slot:header>
                    <thead class="bg-gray-50 text-left text-gray-500">
                        <tr>
                            <x-ui.sno-th />
                            <th class="px-4 py-2">Code</th>
                            <th class="px-4 py-2">Customer</th>
                            <th class="px-4 py-2">{{ $tab === 'parcel' ? 'Rider' : ($tab === 'food' ? 'Restaurant' : 'Store') }}</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Price</th>
                            <x-ui.tat-heads />
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recent as $o)
                            <tr class="border-t">
                                <x-ui.sno :rows="$recent" :loop="$loop" />
                                <td class="px-4 py-2 font-mono text-xs">@if ($row['route'])<a href="{{ $row['route'] }}{{ str_contains($row['route'], '?') ? '&' : '?' }}search={{ urlencode($o->code) }}" class="text-blue-700 hover:underline">{{ $o->code }}</a>@else{{ $o->code }}@endif</td>
                                <td class="px-4 py-2">{{ $o->customer->name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $tab === 'parcel' ? ($o->assignedWorker?->user?->name ?? '—') : ($o->store->name ?? '—') }}</td>
                                <td class="px-4 py-2"><x-ui.badge>{{ str_replace('_', ' ', $o->status) }}</x-ui.badge></td>
                                <td class="px-4 py-2">{{ $currencySymbol }}{{ number_format($o->orderTotalPrice(), 2) }}</td>
                                <x-ui.tat-cells :record="$o" :done="$tab === 'parcel' ? 'delivered_at' : 'completed_at'" />
                            </tr>
                        @empty
                            <tr><td colspan="9"><x-ui.empty-state icon="clipboard" title="No orders yet" /></td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            @endif
        @endif
    @endif
</div>
