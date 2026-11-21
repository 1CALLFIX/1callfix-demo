<div wire:poll.20s>
    @php
        $modColour = ['service' => 'bg-blue-100 text-blue-800', 'parcel' => 'bg-orange-100 text-orange-800', 'shop' => 'bg-violet-100 text-violet-800',
            'food' => 'bg-rose-100 text-rose-800', 'rental' => 'bg-teal-100 text-teal-800', 'hotel' => 'bg-cyan-100 text-cyan-800',
            'events' => 'bg-fuchsia-100 text-fuchsia-800', 'listings' => 'bg-lime-100 text-lime-800', 'taxi' => 'bg-amber-100 text-amber-800'];
        $typeColour = ['home' => 'bg-gray-100 text-gray-700', 'browse' => 'bg-sky-100 text-sky-800', 'item' => 'bg-indigo-100 text-indigo-800',
            'search' => 'bg-yellow-100 text-yellow-800', 'cart' => 'bg-amber-100 text-amber-800', 'checkout' => 'bg-green-100 text-green-800', 'other' => 'bg-gray-100 text-gray-600'];
        $types = \App\Services\Visitors\PageClassifier::TYPE_LABELS;
        $mods = \App\Services\Visitors\PageClassifier::MODULE_LABELS;
        $who = function ($user) use ($canSeePeople) {
            if (! $user) return 'Visitor';
            return $canSeePeople ? $user->name : 'Signed-in customer';
        };
        $pct = fn ($part, $whole) => $whole > 0 ? round($part * 100 / $whole).'%' : '–';
    @endphp

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Live Visitors</h1>
            <p class="text-sm text-gray-500">Who is on the app and website right now, what they look at and what they start to book. Updates by itself every 20 seconds.</p>
        </div>
        @if ($isSuperAdmin)
            <button type="button" wire:click="toggleTracking" data-toggle-tracking
                    class="inline-flex min-h-[44px] items-center gap-2 rounded-xl px-4 text-sm font-semibold {{ $trackingOn ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                <span class="h-2.5 w-2.5 rounded-full {{ $trackingOn ? 'bg-green-600' : 'bg-gray-500' }}"></span>
                Tracking {{ $trackingOn ? 'on' : 'off' }} · tap to switch {{ $trackingOn ? 'off' : 'on' }}
            </button>
        @endif
    </div>

    @if ($flashMessage)
        <div class="mb-4 rounded-xl bg-green-50 px-4 py-2 text-sm text-green-700">{{ $flashMessage }}</div>
    @endif
    @unless ($trackingOn)
        <div class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">Visitor tracking is switched off. The numbers below stop at the moment it was switched off.</div>
    @endunless

    @if ($isSuperAdmin)
        {{-- Popularity line on item pages (owner 2026-11-21, Amazon / OLX pattern). Real counts only. --}}
        <div class="mb-6 rounded-2xl bg-gradient-to-r from-orange-50 to-amber-50 p-4 ring-1 ring-orange-200" data-popularity-settings>
            <h2 class="font-semibold text-orange-900">🔥 Show customers how popular an item is</h2>
            <p class="mb-3 text-sm text-orange-800">Under the photo of each service, shop, product, restaurant, hotel, hall and rental, like Amazon and OLX. Real numbers only; a line shows once its count reaches the number you tap.</p>
            @foreach (['views' => ['👁 "19 people viewed this in the past hour"', 'Show when at least'], 'orders' => ['🔥 "50+ booked in the past month"', 'Show when at least']] as $which => [$example, $lead])
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="min-w-[16rem] text-sm font-medium text-gray-800">{{ $example }}</span>
                    @foreach ($popularityChoices[$which] as $choice)
                        <button type="button" wire:click="setPopularity('{{ $which }}', {{ $choice }})" data-popularity-chip="{{ $which }}-{{ $choice }}"
                                class="min-h-[44px] min-w-[44px] rounded-full px-4 text-sm font-semibold {{ $popularity[$which] === $choice ? ($choice === 0 ? 'bg-gray-700 text-white' : 'bg-orange-600 text-white') : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-orange-100' }}">
                            {{ $choice === 0 ? 'Off' : $choice.'+ '.($which === 'views' ? 'people' : 'orders') }}
                        </button>
                    @endforeach
                </div>
            @endforeach
            <p class="mt-1 text-xs text-orange-800">Orders show rounded down (10+, 50+, 100+, 1K+). Rentals, hotels and events show views only for now.</p>
        </div>
    @endif

    {{-- 1. Right now --}}
    <h2 class="mb-2 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-emerald-700"><span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span> Right now</h2>
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
        <div class="rounded-2xl bg-gradient-to-br from-emerald-500 to-green-600 p-4 text-white shadow-sm" data-tile="online">
            <p class="text-sm opacity-90">Online now</p>
            <p class="text-3xl font-bold">{{ $online['total'] }}</p>
            <p class="text-xs opacity-80">seen in the last {{ $onlineMinutes }} min</p>
        </div>
        <div class="rounded-2xl bg-blue-50 p-4 ring-1 ring-blue-100" data-tile="app"><p class="text-sm text-blue-700">📱 In the app</p><p class="text-3xl font-bold text-blue-900">{{ $online['app'] }}</p></div>
        <div class="rounded-2xl bg-indigo-50 p-4 ring-1 ring-indigo-100" data-tile="web"><p class="text-sm text-indigo-700">🌐 On the website</p><p class="text-3xl font-bold text-indigo-900">{{ $online['web'] }}</p></div>
        <div class="rounded-2xl bg-violet-50 p-4 ring-1 ring-violet-100" data-tile="signed-in"><p class="text-sm text-violet-700">👤 Signed in</p><p class="text-3xl font-bold text-violet-900">{{ $online['signed_in'] }}</p></div>
        <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-100" data-tile="booking"><p class="text-sm text-amber-700">🛒 Booking now</p><p class="text-3xl font-bold text-amber-900">{{ $online['booking'] }}</p></div>
    </div>

    <div class="mb-8 rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
        <div class="border-b px-4 py-3"><h3 class="font-semibold">What they are looking at</h3></div>
        <div class="divide-y">
            @forelse ($online['people'] as $p)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3" data-online-row>
                    <span class="text-lg" title="{{ $p->channel === 'app' ? 'App' : 'Website' }}">{{ $p->channel === 'app' ? '📱' : '🌐' }}</span>
                    <span class="min-w-[8rem] font-medium text-gray-900">{{ $who($p->user) }}</span>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $typeColour[$p->page_type] ?? 'bg-gray-100' }}">{{ $types[$p->page_type] ?? $p->page_type }}</span>
                    @if ($p->module)<span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $modColour[$p->module] ?? 'bg-gray-100' }}">{{ $mods[$p->module] ?? $p->module }}</span>@endif
                    <a href="{{ url($p->path) }}" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-sm text-blue-700 hover:underline">{{ $p->title ?: $p->path }}</a>
                    <span class="text-xs text-gray-500">{{ $p->city?->name ? $p->city->name.' · ' : '' }}{{ $p->pages }} {{ \Illuminate\Support\Str::plural('page', $p->pages) }} · on for {{ $p->started_at->diffForHumans(null, true) }}</span>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-gray-500">Nobody is on the app or website right now.</p>
            @endforelse
        </div>
    </div>

    {{-- 2. About to book --}}
    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-amber-700">🛒 About to book (last {{ $aboutMinutes }} min)</h2>
    <div class="mb-8 rounded-2xl bg-white shadow-sm ring-1 ring-amber-200">
        <div class="divide-y">
            @forelse ($aboutToBook as $v)
                @php($dial = ($canSeePeople && $v->user?->phone) ? \App\Livewire\PartnerPage\Leads::dialable($v->user->phone) : null)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3" data-about-row>
                    <span class="text-lg">{{ $v->channel === 'app' ? '📱' : '🌐' }}</span>
                    <div class="min-w-[12rem] flex-1">
                        <p class="font-medium text-gray-900">{{ $who($v->user) }}
                            <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $typeColour[$v->page_type] ?? 'bg-gray-100' }}">{{ $types[$v->page_type] ?? $v->page_type }}</span>
                            @if ($v->module)<span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $modColour[$v->module] ?? 'bg-gray-100' }}">{{ $mods[$v->module] ?? $v->module }}</span>@endif
                        </p>
                        <p class="truncate text-sm text-gray-600">{{ $v->title ?: $v->path }}{{ $v->city?->name ? ' · '.$v->city->name : '' }} · {{ $v->created_at->diffForHumans() }}</p>
                    </div>
                    @if ($dial)
                        <a href="tel:{{ $dial['tel'] }}" class="inline-flex min-h-[44px] items-center rounded-xl bg-green-600 px-4 font-semibold text-white hover:bg-green-700">📞 Call</a>
                        <a href="{{ $dial['wa'] }}" target="_blank" rel="noopener" class="inline-flex min-h-[44px] items-center rounded-xl bg-emerald-100 px-4 font-semibold text-emerald-800 hover:bg-emerald-200">WhatsApp</a>
                    @endif
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-gray-500">Nobody opened a cart or booking page in the last {{ $aboutMinutes }} minutes.</p>
            @endforelse
        </div>
        <p class="border-t bg-amber-50/50 px-4 py-2 text-xs text-gray-500">Guests show as "Visitor". Signed-in customers show their name{{ $canSeePeople ? ' and a Call button: ask if they need help finishing the booking.' : ' to staff who can open Customers.' }}</p>
    </div>

    {{-- 3. Period numbers --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h2 class="mr-2 text-sm font-bold uppercase tracking-wide text-indigo-700">📊 Visits and bookings</h2>
        @foreach ($periods as $key => $label)
            <button type="button" wire:click="setPeriod('{{ $key }}')"
                    class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ $period === $key ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' }}">{{ $label }}</button>
        @endforeach
    </div>
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-2xl bg-sky-50 p-4 ring-1 ring-sky-100" data-tile="visitors"><p class="text-sm text-sky-700">Visitors</p><p class="text-3xl font-bold text-sky-900">{{ number_format($totals['visitors']) }}</p><p class="text-xs text-sky-700">📱 {{ number_format($totals['app']) }} · 🌐 {{ number_format($totals['web']) }}</p></div>
        <div class="rounded-2xl bg-indigo-50 p-4 ring-1 ring-indigo-100" data-tile="views"><p class="text-sm text-indigo-700">Pages opened</p><p class="text-3xl font-bold text-indigo-900">{{ number_format($totals['views']) }}</p><p class="text-xs text-indigo-700">{{ number_format($totals['signed_in']) }} signed-in customers</p></div>
        <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-100" data-tile="started"><p class="text-sm text-amber-700">Started to book</p><p class="text-3xl font-bold text-amber-900">{{ number_format($totals['started']) }}</p><p class="text-xs text-amber-700">{{ $pct($totals['started'], $totals['visitors']) }} of visitors</p></div>
        <div class="rounded-2xl bg-green-50 p-4 ring-1 ring-green-100" data-tile="orders"><p class="text-sm text-green-700">Orders placed</p><p class="text-3xl font-bold text-green-900">{{ $totals['orders'] === null ? '–' : number_format($totals['orders']) }}</p><p class="text-xs text-green-700">all modules you can see</p></div>
    </div>

    <div class="mb-6 rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
        <div class="border-b px-4 py-3"><h3 class="font-semibold">From looking to booking, by module</h3><p class="text-xs text-gray-500">People counted once per step. The biggest drop shows where people give up.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr><th class="px-4 py-2">Module</th><th class="px-4 py-2 text-right">Came to it</th><th class="px-4 py-2 text-right">Looked at an item</th><th class="px-4 py-2 text-right">Started to book</th><th class="px-4 py-2 text-right">Orders placed</th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($funnel as $key => $f)
                        <tr data-funnel-row="{{ $key }}">
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $modColour[$key] ?? 'bg-gray-100' }}">{{ $f['label'] }}</span></td>
                            <td class="px-4 py-3 text-right font-semibold">{{ number_format($f['visited']) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($f['viewed']) }} <span class="text-xs text-gray-400">{{ $pct($f['viewed'], $f['visited']) }}</span></td>
                            <td class="px-4 py-3 text-right">{{ number_format($f['started']) }} <span class="text-xs text-gray-400">{{ $pct($f['started'], $f['visited']) }}</span></td>
                            <td class="px-4 py-3 text-right font-semibold text-green-700">{{ $f['orders'] === null ? '–' : number_format($f['orders']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No visits in this period yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 md:col-span-2">
            <div class="border-b px-4 py-3"><h3 class="font-semibold">🔥 Most viewed</h3></div>
            <div class="divide-y">
                @forelse ($topItems as $i => $item)
                    <div class="flex items-center gap-3 px-4 py-2.5" data-top-item>
                        <span class="w-6 text-right text-sm font-bold text-gray-400">{{ $i + 1 }}</span>
                        @if ($item->module)<span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $modColour[$item->module] ?? 'bg-gray-100' }}">{{ $mods[$item->module] ?? $item->module }}</span>@endif
                        <a href="{{ url($item->path) }}" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-sm text-blue-700 hover:underline">{{ $item->title ?: $item->path }}</a>
                        <span class="whitespace-nowrap text-sm font-semibold">{{ number_format($item->views) }}</span>
                        <span class="whitespace-nowrap text-xs text-gray-500">{{ number_format($item->visitors) }} people</span>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-gray-500">No items viewed in this period yet.</p>
                @endforelse
            </div>
        </div>
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b px-4 py-3"><h3 class="font-semibold">🔗 Came from</h3><p class="text-xs text-gray-500">Other websites and apps (Google, Facebook, WhatsApp…)</p></div>
            <div class="divide-y">
                @forelse ($sources as $s)
                    <div class="flex items-center justify-between px-4 py-2.5 text-sm"><span class="truncate">{{ $s->referrer_host }}</span><span class="font-semibold">{{ number_format($s->visitors) }}</span></div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-gray-500">Everyone came straight in (typed the address, the app, or a saved link).</p>
                @endforelse
            </div>
        </div>
    </div>

    <p class="text-xs text-gray-400">Counted: people on the customer app and website. Not counted: staff accounts, the partner and rider apps, and search-engine robots. Visitors are a random id in their browser; history older than {{ (int) config('visitors.keep_days', 90) }} days is deleted.</p>
</div>
