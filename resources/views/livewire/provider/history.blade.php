<div>
    <x-provider.jobs-tabs active="history" />

    <h1 class="mt-4 text-xl font-bold tracking-tight">Job history</h1>

    <div class="mt-3 flex flex-wrap gap-2 text-sm">
        @foreach (['' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    @if ($filter === $key) aria-pressed="true" @else aria-pressed="false" @endif
                    @class([
                        'min-h-11 rounded-full px-4 font-medium',
                        'bg-slate-900 text-white' => $filter === $key,
                        'bg-white text-slate-700 border border-slate-300' => $filter !== $key,
                    ])>{{ $label }}</button>
        @endforeach
    </div>

    @php
        $tz = app(\App\Services\TimezoneResolver::class);
        $badge = fn (string $status) => match (true) {
            $status === 'completed' => 'bg-emerald-100 text-emerald-800',
            $status === 'cancelled' => 'bg-rose-100 text-rose-800',
            in_array($status, ['assigned', 'provider_en_route', 'in_progress', 'on_hold'], true) => 'bg-blue-100 text-blue-800',
            default => 'bg-slate-100 text-slate-700',
        };
    @endphp

    <div class="mt-4 space-y-2">
        @forelse ($bookings as $booking)
            @php $when = $booking->completed_at ?? $booking->scheduled_at ?? $booking->created_at; @endphp
            <a href="{{ route('provider.jobs.show', $booking) }}" wire:navigate class="block">
                <x-ui.card class="!p-4 hover:bg-slate-50">
                    <div class="flex items-start justify-between gap-3 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $booking->service?->name ?? 'Service' }}</p>
                            <p class="text-xs text-slate-500">{{ $booking->code }} · {{ $booking->address?->label }}</p>
                            @if ($when)
                                <p class="mt-0.5 text-xs text-slate-500">{{ $tz->format($when, $booking->franchise, 'j M Y, g:i A') }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize {{ $badge($booking->status) }}">{{ str_replace('_', ' ', $booking->status) }}</span>
                            @php $amount = $booking->price_final ?? $booking->price_quoted; @endphp
                            @if ($amount !== null)
                                <span class="text-sm font-semibold text-slate-900">₹{{ number_format((float) $amount, 2) }}</span>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            </a>
        @empty
            <x-ui.card class="!p-6 text-center text-sm text-slate-500">No jobs yet. Jobs you accept show up here.</x-ui.card>
        @endforelse
    </div>

    <div class="mt-4">{{ $bookings->links() }}</div>
</div>
