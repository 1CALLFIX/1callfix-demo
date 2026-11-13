<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
?>
<div class="mx-auto max-w-xl px-4 py-6 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight">{{ $t('event.tickets.title') }}</h1>
    <ul class="mt-4 space-y-3">
        @forelse ($orders as $o)
            <li>
                <a href="{{ route('customer.events.order', $o->code) }}" wire:navigate @class(['flex gap-3 rounded-2xl border border-slate-200 bg-white p-3', 'opacity-60' => $o->event->ends_at->isPast()]) data-testid="my-ticket-order">
                    <div class="h-16 w-24 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                        @if ($o->event->coverUrl()) <img src="{{ $o->event->coverUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy"> @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">{{ $o->event->title }}</p>
                        <p class="text-sm text-slate-600">{{ $o->event->starts_at->copy()->setTimezone($tz)->format('D, j M · g:i A') }}</p>
                        <p class="text-xs text-slate-500">{{ $o->ticket_count }} × · {{ $o->status === 'pending' ? $t('event.order.pay_now') : $o->code }}</p>
                    </div>
                </a>
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">{{ $t('event.tickets.empty') }}</li>
        @endforelse
    </ul>
</div>
