<div>
    <h1 class="text-xl font-bold tracking-tight">Activity</h1>
    <p class="text-sm text-slate-500">Your jobs, offers, earnings and status changes.</p>

    <div class="mt-4 space-y-2">
        @forelse ($feed as $row)
            <div class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm">
                <div class="flex items-start gap-2">
                    <span @class([
                        'mt-0.5 text-xs font-semibold uppercase',
                        'text-emerald-600' => $row['kind'] === 'credit',
                        'text-rose-600' => $row['kind'] === 'debit',
                        'text-blue-600' => $row['kind'] === 'offer',
                        'text-slate-500' => in_array($row['kind'], ['job', 'status'], true),
                    ])>{{ $row['kind'] }}</span>
                    <span>{{ $row['text'] }}</span>
                </div>
                <span class="shrink-0 text-xs text-slate-400">{{ $row['at']->format('j M, g:i A') }}</span>
            </div>
        @empty
            <div class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500">
                Nothing here yet.
            </div>
        @endforelse
    </div>

    @if ($hasMore)
        <button type="button" wire:click="showMore"
                class="mt-4 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Show more
        </button>
    @endif
</div>
