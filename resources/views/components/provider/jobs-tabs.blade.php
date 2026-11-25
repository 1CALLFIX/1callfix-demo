@props(['active' => 'offers', 'offers' => 0])
{{-- Owner 2026-11-25: past jobs belong on the Jobs tab, not hidden under More. Two big tabs at the top of
     Jobs: New offers (with a count while offers wait) and Job history. --}}
<nav aria-label="Jobs" data-testid="partner-jobs-tabs" class="grid grid-cols-2 gap-2 rounded-2xl bg-slate-100 p-1">
    <a href="{{ route('provider.jobs.index') }}" wire:navigate
       @if ($active === 'offers') aria-current="page" @endif
       @class([
           'flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 text-sm font-semibold',
           'bg-emerald-600 text-white shadow-sm' => $active === 'offers',
           'text-slate-700 hover:bg-white' => $active !== 'offers',
       ])>
        <span aria-hidden="true">🔔</span> New offers
        @if ($offers > 0)
            <span @class([
                'rounded-full px-2 py-0.5 text-xs font-bold',
                'bg-white text-emerald-700' => $active === 'offers',
                'bg-emerald-600 text-white' => $active !== 'offers',
            ])>{{ $offers }}</span>
        @endif
    </a>
    <a href="{{ route('provider.history') }}" wire:navigate
       @if ($active === 'history') aria-current="page" @endif
       @class([
           'flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 text-sm font-semibold',
           'bg-blue-600 text-white shadow-sm' => $active === 'history',
           'text-slate-700 hover:bg-white' => $active !== 'history',
       ])>
        <span aria-hidden="true">🕘</span> Job history
    </a>
</nav>
