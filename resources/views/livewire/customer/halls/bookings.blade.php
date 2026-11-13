<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
?>
<div class="mx-auto max-w-xl px-4 py-6 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight">{{ $t('hall.bookings.title') }}</h1>
    <ul class="mt-4 space-y-3">
        @forelse ($bookings as $b)
            <li>
                <a href="{{ route('customer.halls.booking', $b->code) }}" wire:navigate @class(['flex gap-3 rounded-2xl border border-slate-200 bg-white p-3', 'opacity-60' => $b->ends_at->isPast() || in_array($b->status, ['declined', 'cancelled'], true)]) data-testid="my-hall-booking">
                    <div class="h-16 w-24 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                        @if ($b->hall?->coverUrl()) <img src="{{ $b->hall->coverUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy"> @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">{{ $b->hall?->name }}</p>
                        <p class="text-sm text-slate-600">{{ $b->event_date->format('D, j M Y') }} · {{ $b->slot_name }}</p>
                        <p class="text-xs text-slate-500">{{ $t('hall.status.'.$b->status) }} · {{ $b->code }}</p>
                    </div>
                </a>
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">{{ $t('hall.bookings.empty') }}</li>
        @endforelse
    </ul>
</div>
