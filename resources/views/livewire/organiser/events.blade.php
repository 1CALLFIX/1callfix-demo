<div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Your events</h1>
        <a href="{{ route('organiser.events.create') }}" wire:navigate class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">+ New event</a>
    </div>

    @forelse ($events as $e)
        <?php $tone = ['approved' => 'bg-emerald-50 text-emerald-800', 'in_review' => 'bg-amber-50 text-amber-800', 'rejected' => 'bg-red-50 text-red-700', 'cancelled' => 'bg-slate-100 text-slate-600', 'completed' => 'bg-slate-100 text-slate-600'][$e->status] ?? 'bg-slate-100 text-slate-700'; ?>
        <a href="{{ route('organiser.events.edit', $e->id) }}" wire:navigate class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-3" data-testid="organiser-event-row">
            <div class="h-16 w-24 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                @if ($e->coverUrl()) <img src="{{ $e->coverUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy"> @endif
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold">{{ $e->title }}</p>
                <p class="text-sm text-slate-600">{{ $e->starts_at->timezone($tz)->format('D, j M Y · g:i A') }}</p>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full px-2 py-0.5 font-medium {{ $tone }}">{{ $e->statusLabel() }}</span>
                    <span class="text-slate-500">{{ $e->ticket_types_count }} ticket {{ \Illuminate\Support\Str::plural('type', $e->ticket_types_count) }} · {{ (int) $e->tickets_total }} tickets</span>
                </p>
            </div>
        </a>
    @empty
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
            No events yet. Create your first one: add the date, venue, a cover photo and your ticket types, then send it for review.
        </div>
    @endforelse
</div>
