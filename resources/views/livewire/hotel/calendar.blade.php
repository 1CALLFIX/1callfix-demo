<?php $inp = 'mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600'; ?>
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Calendar</h1>
        <p class="mt-1 text-sm text-slate-600">Close nights when you are full, and set special prices for busy days.</p>
    </div>

    @if ($notice)
        <div role="status" class="rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    @if ($rooms->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
            Add a room first. <a href="{{ route('hotel.rooms') }}" wire:navigate class="font-medium text-blue-600 underline">Go to Rooms</a>
        </div>
    @else
        <div>
            <label for="c-room" class="block text-sm font-medium text-slate-900">Room</label>
            <select id="c-room" wire:model.live="roomTypeId" class="{{ $inp }}">
                @foreach ($rooms as $r)
                    <option value="{{ $r->id }}">{{ $r->name }}{{ $r->is_active ? '' : ' (off sale)' }}</option>
                @endforeach
            </select>
        </div>

        <form wire:submit="apply" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
            <h2 class="text-sm font-semibold uppercase text-slate-500">Change dates</h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="c-from" class="block text-sm font-medium text-slate-900">From</label>
                    <input id="c-from" type="date" wire:model="from" class="{{ $inp }}">
                    @error('from') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-to" class="block text-sm font-medium text-slate-900">To (last night)</label>
                    <input id="c-to" type="date" wire:model="to" class="{{ $inp }}">
                    @error('to') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div x-data="{ a: @entangle('action') }">
                <label for="c-action" class="block text-sm font-medium text-slate-900">What to do</label>
                <select id="c-action" x-model="a" class="{{ $inp }}">
                    <option value="close">Close (no new bookings)</option>
                    <option value="open">Open again</option>
                    <option value="price">Set a special price</option>
                    <option value="normal">Back to the normal price</option>
                </select>
                <div x-show="a === 'price'" class="mt-3">
                    <label for="c-price" class="block text-sm font-medium text-slate-900">Price per night (₹)</label>
                    <input id="c-price" type="text" inputmode="decimal" wire:model="price" class="{{ $inp }}">
                    @error('price') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="apply">Apply</x-ui.button>
        </form>

        @if ($room)
            <div class="flex items-center justify-between">
                <x-ui.button type="button" variant="secondary" wire:click="previous" :disabled="$atToday">← Earlier</x-ui.button>
                <x-ui.button type="button" variant="secondary" wire:click="next">Later →</x-ui.button>
            </div>
            <ul class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white" data-testid="hotel-calendar-days">
                @foreach ($days as $d)
                    <li class="flex min-h-14 items-center justify-between gap-3 px-4 py-2">
                        <div>
                            <p class="text-sm font-semibold">{{ $d['date']->format('D, j M') }}</p>
                            <p class="text-xs text-slate-500">{{ $d['booked'] }} booked · {{ $d['left'] }} left</p>
                        </div>
                        <div class="text-right">
                            <p @class(['text-sm font-semibold', 'text-amber-700' => $d['special']])>{{ $d['price'] !== null ? '₹'.number_format($d['price']) : '—' }}</p>
                            <span @class(['inline-block rounded-full px-2 text-[11px] font-semibold',
                                          'bg-emerald-50 text-emerald-700' => $d['open'] && $d['left'] > 0,
                                          'bg-slate-100 text-slate-600' => $d['open'] && $d['left'] === 0,
                                          'bg-red-50 text-red-700' => ! $d['open']])>
                                {{ ! $d['open'] ? 'Closed' : ($d['left'] === 0 ? 'Full' : 'Open') }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
