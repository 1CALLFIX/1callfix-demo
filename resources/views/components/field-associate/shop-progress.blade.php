{{-- Field associates (owner 2026-11-23): one shop an associate signed up, with its 5-step progress bar. Used on the
     associate's own page and on Admin → Field associates. $row = FieldAssociateTracker::shops() item. --}}
@props(['row'])
@php
    $store = $row['store'];
    $p = $row['progress'];
    $earned = (float) $row['rewards']->sum('amount');
    [$badge, $badgeClass] = match (true) {
        $p['rejected'] => ['Not approved', 'bg-red-100 text-red-800 ring-red-200'],
        $p['percent'] === 100 => ['All done', 'bg-emerald-100 text-emerald-800 ring-emerald-200'],
        $store->approved_at !== null => ['Live', 'bg-emerald-100 text-emerald-800 ring-emerald-200'],
        default => ['In progress', 'bg-amber-100 text-amber-800 ring-amber-200'],
    };
    $barClass = $p['rejected'] ? 'bg-red-400' : ($p['percent'] === 100 ? 'bg-emerald-500' : ($p['percent'] >= 60 ? 'bg-blue-500' : 'bg-amber-400'));
@endphp
<li {{ $attributes->merge(['class' => 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200']) }} data-testid="fa-shop">
    <div class="flex items-start gap-3">
        <span aria-hidden="true" class="grid h-11 w-11 shrink-0 place-items-center rounded-xl {{ $store->isRestaurant() ? 'bg-rose-100' : 'bg-violet-100' }} text-xl">{{ $store->isRestaurant() ? '🍽️' : '🏪' }}</span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <p class="truncate font-semibold text-slate-900">{{ $store->name }}</p>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $badgeClass }}" data-testid="fa-shop-badge">{{ $badge }}</span>
            </div>
            <p class="mt-0.5 text-xs text-slate-500">Signed up {{ $store->created_at?->format('j M Y') }}</p>
        </div>
        @if ($earned > 0)
            <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-sm font-bold text-emerald-700 ring-1 ring-emerald-200" data-testid="fa-shop-earned">{{ \App\Services\FieldAssociates\FieldAssociateTracker::money($earned) }}</span>
        @endif
    </div>

    <div class="mt-3 h-3 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $p['percent'] }}" aria-label="{{ $store->name }}: {{ $p['percent'] }}% done">
        <div class="h-full rounded-full {{ $barClass }} transition-all" style="width: {{ max(4, $p['percent']) }}%" data-testid="fa-shop-bar"></div>
    </div>

    <ol class="mt-3 grid grid-cols-5 gap-1 text-center">
        @foreach ($p['steps'] as $step)
            <li class="flex flex-col items-center gap-1" data-testid="fa-step-{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                <span @class(['grid h-8 w-8 place-items-center rounded-full text-sm',
                    'bg-emerald-500 text-white' => $step['done'],
                    'bg-slate-100 text-slate-400 grayscale' => ! $step['done']])>{{ $step['done'] ? '✓' : $step['emoji'] }}</span>
                <span @class(['text-[11px] leading-tight', 'font-semibold text-slate-800' => $step['done'], 'text-slate-500' => ! $step['done']])>{{ $step['label'] }}@if ($step['reward']) <span aria-label="reward step">💰</span>@endif</span>
            </li>
        @endforeach
    </ol>

    <p @class(['mt-3 rounded-lg px-3 py-2 text-sm',
        'bg-red-50 text-red-800' => $p['rejected'],
        'bg-emerald-50 text-emerald-800' => ! $p['rejected'] && $p['percent'] === 100,
        'bg-slate-50 text-slate-700' => ! $p['rejected'] && $p['percent'] < 100]) data-testid="fa-shop-next">{{ $p['next'] }}</p>
    {{ $slot }}
</li>
