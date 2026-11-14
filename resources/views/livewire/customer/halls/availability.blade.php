<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<section class="rounded-2xl border border-slate-200 bg-white p-4" data-testid="hall-availability">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">{{ $t('hall.show.availability') }}</h2>
        <div class="flex items-center gap-1">
            <button type="button" wire:click="move(-1)" @disabled(! $canBack) class="flex h-11 w-11 items-center justify-center rounded-full border border-slate-300 text-lg disabled:opacity-30" aria-label="Previous month">‹</button>
            <span class="min-w-28 text-center text-sm font-semibold">{{ $shownMonth->format('F Y') }}</span>
            <button type="button" wire:click="move(1)" @disabled(! $canNext) class="flex h-11 w-11 items-center justify-center rounded-full border border-slate-300 text-lg disabled:opacity-30" aria-label="Next month">›</button>
        </div>
    </div>
    <div class="mt-3 grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-slate-500">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) <span>{{ $d }}</span> @endforeach
    </div>
    <div class="mt-1 grid grid-cols-7 gap-1" wire:loading.class="opacity-50">
        @for ($i = 0; $i < $lead; $i++) <span></span> @endfor
        @foreach ($states as $date => $state)
            @php $day = (int) substr($date, 8, 2); @endphp
            @if ($state === 'free' || $state === 'part')
                <a href="{{ $bookUrl($date) }}" wire:navigate data-state="{{ $state }}"
                   title="{{ $state === 'free' ? $t('hall.show.avail_free') : $t('hall.show.avail_part') }}"
                   @class(['flex h-10 items-center justify-center rounded-lg text-sm font-semibold',
                       'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' => $state === 'free',
                       'bg-amber-100 text-amber-800 hover:bg-amber-200' => $state === 'part'])>{{ $day }}</a>
            @else
                <span data-state="{{ $state }}" title="{{ $state === 'full' ? $t('hall.show.avail_full') : $t('hall.show.avail_closed') }}"
                      @class(['flex h-10 items-center justify-center rounded-lg text-sm',
                          'bg-red-100 font-semibold text-red-700 line-through' => $state === 'full',
                          'text-slate-300' => $state === 'closed'])>{{ $day }}</span>
            @endif
        @endforeach
    </div>
    <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
        <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-emerald-200"></span>{{ $t('hall.show.avail_free') }}</li>
        <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-amber-200"></span>{{ $t('hall.show.avail_part') }}</li>
        <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-red-200"></span>{{ $t('hall.show.avail_full') }}</li>
    </ul>
    <p class="mt-1 text-xs text-slate-500">{{ $t('hall.show.avail_hint') }}</p>
</section>
