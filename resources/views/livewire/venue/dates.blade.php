<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Blocked dates</h1>
        <p class="mt-1 text-sm text-slate-600">Already booked outside 1CallFix (a walk-in, a family function)? Block the day, or just one slot, so no customer can book it.</p>
    </div>

    @if ($halls->count() > 1)
        <div>
            <label for="d-hall" class="block text-sm font-medium text-slate-900">Hall</label>
            <select id="d-hall" wire:model.live="hallId" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base">
                @foreach ($halls as $h) <option value="{{ $h->id }}">{{ $h->name }}</option> @endforeach
            </select>
        </div>
    @endif

    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="dates-notice">{{ $notice }}</div> @endif

    @if ($hall)
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Block a date</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="d-date" class="block text-sm font-medium text-slate-900">Date</label>
                    <input id="d-date" type="date" min="{{ $today }}" wire:model="date" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('date') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="d-slot" class="block text-sm font-medium text-slate-900">Slot</label>
                    <select id="d-slot" wire:model="slotId" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base">
                        <option value="">Whole day</option>
                        @foreach ($slots as $s) <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->timeLabel() }})</option> @endforeach
                    </select>
                    @error('slotId') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="d-note" class="block text-sm font-medium text-slate-900">Note <span class="font-normal text-slate-500">(only you see it)</span></label>
                    <input id="d-note" type="text" wire:model="note" maxlength="200" placeholder="e.g. Reddy wedding" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                </div>
            </div>
            <x-ui.button wire:click="block" data-testid="block-date">Block</x-ui.button>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Coming up</h2>
            @forelse ($blocked as $b)
                <div class="flex items-center justify-between gap-3 border-t py-3 text-sm first:border-t-0" data-testid="blocked-row">
                    <div>
                        <p class="font-medium">{{ \App\Livewire\Venue\Dates::dayLabel($b->date) }} · {{ $b->slot?->name ?? 'Whole day' }}</p>
                        @if ($b->note) <p class="text-slate-500">{{ $b->note }}</p> @endif
                    </div>
                    <button type="button" wire:click="unblock({{ $b->id }})" class="min-h-11 rounded-lg px-3 font-medium text-blue-700 hover:bg-blue-50">Open again</button>
                </div>
            @empty
                <p class="mt-2 text-sm text-slate-500">No blocked dates. Every day is open for bookings.</p>
            @endforelse
        </section>
    @endif
</div>
