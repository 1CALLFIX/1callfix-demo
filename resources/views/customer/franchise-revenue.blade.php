{{-- Revenue potential (owner 2026-11-17): orders x average order x commission, scenario buttons. Every figure is a Super Admin field ($p = FranchisePage content). --}}
@php
    $inr = function ($n) {
        $s = (string) (int) round((float) $n);
        if (strlen($s) <= 3) { return '₹'.$s; }
        return '₹'.preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)).','.substr($s, -3);
    };
    $rev = \App\Support\InfoPages\FranchisePage::revenueModel($p['streams'] ?? []);
    $revScen = collect([['rev_scen_low', 'rev_scen_low_pct'], ['rev_scen_mid', 'rev_scen_mid_pct'], ['rev_scen_high', 'rev_scen_high_pct']])
        ->filter(fn ($s) => is_numeric($p[$s[1]] ?? '') && (float) $p[$s[1]] > 0)
        ->map(fn ($s) => ['label' => (string) $p[$s[0]], 'f' => (float) $p[$s[1]] / 100])->values();
    $revStart = $revScen->count() ? $revScen->count() - 1 : 0;
    $revLook = ['from-blue-600 to-sky-500', 'from-rose-600 to-red-500', 'from-emerald-600 to-teal-500', 'from-violet-600 to-indigo-500', 'from-amber-500 to-orange-500', 'from-fuchsia-600 to-pink-500', 'from-sky-600 to-cyan-500', 'from-lime-600 to-green-500'];
    $revCost = ctype_digit((string) ($p['rev_cost'] ?? '')) && (int) $p['rev_cost'] > 0 ? (int) $p['rev_cost'] : 0;
    $revRoyalty = is_numeric($p['rev_royalty_pct'] ?? '') ? (float) $p['rev_royalty_pct'] : 0;
    $revRows = collect($rev['rows'])->map(fn ($r) => ['orders' => (float) $r['orders'], 'value' => (float) $r['value'], 'pct' => (float) $r['pct'], 'extra' => (float) ($r['extra'] ?: 0)])->values();
@endphp
@if ($rev['rows'] && $revScen->count())
    <section class="mx-auto max-w-5xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="fr-rev" data-franchise-revenue
             x-data="{ scen: {{ $revScen->toJson() }}, i: {{ $revStart }}, cost: {{ $revCost }}, royalty: {{ $revRoyalty }}, rows: {{ $revRows->toJson() }},
                       f() { return this.scen[this.i].f },
                       earn(r) { return r.orders * this.f() * (r.value * r.pct / 100 + r.extra) },
                       total() { return this.rows.reduce((a, r) => a + this.earn(r), 0) },
                       orders() { return Math.round(this.rows.reduce((a, r) => a + r.orders * this.f(), 0)) },
                       net() { return this.total() * (1 - this.royalty / 100) - this.cost },
                       money(n) { return '₹' + Math.round(n).toLocaleString('en-IN') } }">
        <div class="max-w-2xl">
            <h2 id="fr-rev" class="flex items-center gap-2 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl"><x-icon name="chart-bar" class="h-7 w-7 text-emerald-600" /> {{ $p['rev_title'] }}</h2>
            @if ($p['rev_intro'])<p class="mt-3 text-base text-slate-600">{{ $p['rev_intro'] }}</p>@endif
        </div>
        @if ($revScen->count() > 1)
            <div class="mt-6 inline-flex flex-wrap gap-2 rounded-full bg-slate-100 p-1" role="group" aria-label="{{ $p['rev_title'] }}">
                @foreach ($revScen as $k => $s)
                    <button type="button" @click="i = {{ $k }}" :class="i === {{ $k }} ? 'bg-slate-900 text-white shadow' : 'text-slate-700 hover:bg-white'"
                            class="min-h-[44px] rounded-full px-5 text-sm font-bold transition" :aria-pressed="i === {{ $k }}">{{ $s['label'] }}</button>
                @endforeach
            </div>
        @endif
        <ul class="mt-6 grid gap-3 sm:grid-cols-2">
            @foreach ($rev['rows'] as $k => $r)
                <li class="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200" data-franchise-revenue-row>
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-gradient-to-br {{ $revLook[$k % count($revLook)] }} text-white shadow"><x-icon :name="$r['icon']" class="h-6 w-6" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-bold text-slate-900">{{ $r['name'] }}</span>
                        <span class="block text-xs text-slate-500"><span x-text="Math.round(rows[{{ $k }}].orders * f())">{{ number_format((float) $r['orders']) }}</span> orders × {{ $inr($r['value']) }} × {{ rtrim(rtrim(number_format((float) $r['pct'], 2), '0'), '.') }}%@if ((float) ($r['extra'] ?: 0) > 0) + ₹{{ rtrim(rtrim(number_format((float) $r['extra'], 2), '0'), '.') }}@endif</span>
                    </span>
                    <span class="text-right text-base font-extrabold text-slate-900" x-text="money(earn(rows[{{ $k }}]))">{{ $inr($r['earn']) }}</span>
                </li>
            @endforeach
        </ul>
        <div class="mt-4 overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 to-teal-700 p-6 text-white shadow-lg" data-franchise-revenue-total>
            <p class="text-sm font-semibold text-emerald-50">{{ $p['rev_total_label'] }}</p>
            <p class="mt-1 text-4xl font-extrabold tracking-tight" x-text="money(total())">{{ $inr($rev['earn']) }}</p>
            <p class="mt-1 text-xs text-emerald-100"><span x-text="orders().toLocaleString('en-IN')">{{ number_format($rev['orders']) }}</span> orders a month</p>
            @if ($revCost > 0)
                <div class="mt-4 grid gap-1 border-t border-white/25 pt-4 text-sm sm:grid-cols-2">
                    <p class="text-emerald-50">{{ $p['rev_cost_label'] }}: <span class="font-bold text-white">−<span x-text="money(cost)">{{ $inr($revCost) }}</span></span></p>
                    @if ($revRoyalty > 0)<p class="text-emerald-50">Royalty {{ rtrim(rtrim(number_format($revRoyalty, 2), '0'), '.') }}%: <span class="font-bold text-white">−<span x-text="money(total() * royalty / 100)"></span></span></p>@endif
                    <p class="text-base font-bold sm:col-span-2">{{ $p['rev_left_label'] }}: <span class="text-amber-300" x-text="money(net())"></span></p>
                </div>
            @endif
        </div>
        @if ($p['rev_note'])<p class="mt-4 text-xs leading-relaxed text-slate-500">{{ $p['rev_note'] }}</p>@endif
    </section>
@endif
