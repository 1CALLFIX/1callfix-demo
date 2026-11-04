@props([
    'name',               // big name on top (person or shop)
    'subtitle' => null,   // e.g. "Rider ID 12 · 98xxxxxx"
    'tiles' => [],        // quick tiles: [label, href, icon, note?]
    'rows' => [],         // list rows:   [label, href, icon, note?]
    'mode',               // current partner role for the mode switch: service | parcel | commerce
    'logoutRoute',
])
@php
    // Help numbers: the same admin-edited list the partner page shows (Admin → Partners page → Support numbers).
    $phones = \App\Support\PartnerPage\PartnerPageSettings::lines('support_phones');
@endphp
{{-- Partner apps 2026-11-04: the "More" screen — everything that is not a daily tab, in one place, big tap targets. --}}
<div class="space-y-4" data-testid="partner-more">
    <div class="rounded-2xl bg-blue-50 px-5 py-5">
        <p class="text-xl font-bold tracking-tight text-slate-900">{{ $name }}</p>
        @if ($subtitle)<p class="mt-0.5 text-sm font-medium text-slate-600">{{ $subtitle }}</p>@endif
    </div>

    @if ($tiles)
        <div class="grid grid-cols-3 gap-3">
            @foreach ($tiles as $t)
                <a href="{{ $t[1] }}" wire:navigate class="flex min-h-24 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-3 hover:border-blue-300">
                    <x-icon :name="$t[2]" class="h-6 w-6 text-slate-700" />
                    <span class="text-sm font-semibold leading-tight text-slate-800">{{ $t[0] }}</span>
                    @if (! empty($t[3]))<span class="text-xs font-semibold text-rose-700">{{ $t[3] }}</span>@endif
                </a>
            @endforeach
        </div>
    @endif

    @if ($rows || $phones)
    <ul class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">
        @foreach ($rows as $r)
            <li>
                <a href="{{ $r[1] }}" wire:navigate class="flex min-h-14 items-center gap-3 px-4 hover:bg-slate-50">
                    <x-icon :name="$r[2]" class="h-5 w-5 shrink-0 text-slate-600" />
                    <span class="flex-1 text-sm font-medium text-slate-800">{{ $r[0] }}</span>
                    @if (! empty($r[3]))<span class="text-xs font-semibold text-slate-500">{{ $r[3] }}</span>@endif
                    <x-icon name="chevron-right" class="h-4 w-4 text-slate-400" />
                </a>
            </li>
        @endforeach
        @foreach ($phones as $line)
            <li>
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $line) }}" class="flex min-h-14 items-center gap-3 px-4 hover:bg-slate-50">
                    <x-icon name="phone" class="h-5 w-5 shrink-0 text-slate-600" />
                    <span class="flex-1 text-sm font-medium text-slate-800">Help: call {{ $line }}</span>
                    <x-icon name="chevron-right" class="h-4 w-4 text-slate-400" />
                </a>
            </li>
        @endforeach
    </ul>
    @endif

    <div class="flex flex-col text-sm">
        <x-partner.mode-switch :current="$mode" class="flex min-h-12 items-center px-1" />
    </div>

    <form method="POST" action="{{ route($logoutRoute) }}">
        @csrf
        <button type="submit" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-rose-700 hover:bg-rose-50">
            <x-icon name="logout" class="h-5 w-5" /> Sign out
        </button>
    </form>
</div>
