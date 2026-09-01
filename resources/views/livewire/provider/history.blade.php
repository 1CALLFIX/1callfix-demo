<div>
    <h1 class="text-xl font-bold tracking-tight">Job history</h1>

    <div class="mt-4 flex gap-2 text-sm">
        @foreach (['' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 font-medium',
                        'bg-slate-900 text-white' => $filter === $key,
                        'bg-white text-slate-700 border border-slate-300' => $filter !== $key,
                    ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="mt-4 space-y-2">
        @forelse ($bookings as $booking)
            <a href="{{ route('provider.jobs.show', $booking) }}" wire:navigate class="block">
                <x-ui.card class="!p-4 hover:bg-slate-50">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <div>
                            <p class="font-medium">{{ $booking->service?->name ?? 'Service' }}</p>
                            <p class="text-xs text-slate-500">{{ $booking->code }} · {{ $booking->address?->label }}</p>
                        </div>
                        <span class="shrink-0 text-xs font-medium text-slate-600">{{ str_replace('_', ' ', $booking->status) }}</span>
                    </div>
                </x-ui.card>
            </a>
        @empty
            <x-ui.card class="!p-6 text-center text-sm text-slate-500">No jobs yet.</x-ui.card>
        @endforelse
    </div>

    <div class="mt-4">{{ $bookings->links() }}</div>
</div>
