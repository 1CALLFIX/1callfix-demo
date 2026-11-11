<?php
    $inp = 'mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600';
    $url = fn ($p) => $p ? (str_starts_with((string) $p, 'http') ? $p : \Illuminate\Support\Facades\Storage::disk('public')->url($p)) : null;
?>
<div class="space-y-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Rooms</h1>
            <p class="mt-1 text-sm text-slate-600">Each kind of room, how many you have and the price per night.</p>
        </div>
        @unless ($showForm)
            <x-ui.button type="button" wire:click="add" data-testid="hotel-add-room">Add a room</x-ui.button>
        @endunless
    </div>

    @if ($notice)
        <div role="status" class="rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    @if ($showForm)
        <form wire:submit="save" class="space-y-4 rounded-2xl border border-blue-200 bg-white p-4 sm:p-5">
            <h2 class="text-base font-semibold">{{ $editingId ? 'Edit room' : 'New room' }}</h2>
            <div>
                <label for="r-name" class="block text-sm font-medium text-slate-900">Room name</label>
                <input id="r-name" type="text" wire:model="roomName" class="{{ $inp }}" placeholder="Deluxe AC">
                @error('roomName') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="r-price" class="block text-sm font-medium text-slate-900">Price per night (₹)</label>
                    <input id="r-price" type="text" inputmode="decimal" wire:model="price" class="{{ $inp }}">
                    @error('price') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-count" class="block text-sm font-medium text-slate-900">Rooms of this kind</label>
                    <input id="r-count" type="number" min="1" wire:model="inventory" class="{{ $inp }}">
                    @error('inventory') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-adults" class="block text-sm font-medium text-slate-900">Adults per room</label>
                    <input id="r-adults" type="number" min="1" wire:model="maxAdults" class="{{ $inp }}">
                    @error('maxAdults') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-kids" class="block text-sm font-medium text-slate-900">Children per room</label>
                    <input id="r-kids" type="number" min="0" wire:model="maxChildren" class="{{ $inp }}">
                    @error('maxChildren') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="r-beds" class="block text-sm font-medium text-slate-900">Beds</label>
                    <input id="r-beds" type="text" wire:model="beds" class="{{ $inp }}" placeholder="1 king bed">
                    @error('beds') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-meal" class="block text-sm font-medium text-slate-900">Price includes</label>
                    <select id="r-meal" wire:model="mealPlan" class="{{ $inp }} bg-white">
                        @foreach ($mealPlans as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label for="r-about" class="block text-sm font-medium text-slate-900">About this room</label>
                <textarea id="r-about" rows="2" wire:model="roomDescription" class="{{ $inp }}" placeholder="AC, TV, attached bathroom with hot water."></textarea>
                @error('roomDescription') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="r-photos" class="block text-sm font-medium text-slate-900">Room photos</label>
                <input id="r-photos" type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="newPhotos" class="mt-1.5 block w-full text-sm">
                <x-ui.image-size use="hotel_photo" />
                @error('newPhotos') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('newPhotos.*') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="save,newPhotos">Save room</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="lg" wire:click="cancel">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @forelse ($rooms as $room)
        @php $plan = $room->ratePlans->first(); @endphp
        <article @class(['rounded-2xl border bg-white p-4', 'border-slate-200' => $room->is_active, 'border-dashed border-slate-300 opacity-70' => ! $room->is_active]) data-testid="hotel-room-{{ $room->id }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ $room->name }}</h2>
                    <p class="mt-0.5 text-sm text-slate-600">
                        {{ $room->total_inventory }} {{ \Illuminate\Support\Str::plural('room', $room->total_inventory) }} ·
                        {{ $room->max_adults }} {{ \Illuminate\Support\Str::plural('adult', $room->max_adults) }}@if ($room->max_children) + {{ $room->max_children }} {{ \Illuminate\Support\Str::plural('child', $room->max_children) }}@endif
                        @if ($room->bed_configuration) · {{ $room->bed_configuration }} @endif
                    </p>
                </div>
                <p class="shrink-0 text-right">
                    <span class="text-lg font-bold">{{ $plan ? '₹'.number_format((float) $plan->nightly_rate) : '—' }}</span>
                    <span class="block text-xs text-slate-500">per night · {{ $mealPlans[$plan?->meal_plan] ?? 'Room only' }}</span>
                </p>
            </div>
            @if (! empty($room->photos))
                <div class="mt-3 flex gap-2 overflow-x-auto">
                    @foreach ($room->photos as $i => $p)
                        <figure class="relative h-20 w-28 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                            <img src="{{ $url($p) }}" alt="{{ $room->name }} photo {{ $i + 1 }}" class="h-full w-full object-cover" loading="lazy">
                            <button type="button" wire:click="removePhoto({{ $room->id }}, {{ $i }})" wire:confirm="Remove this photo?"
                                    class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-xs font-bold text-slate-700 shadow" aria-label="Remove photo">×</button>
                        </figure>
                    @endforeach
                </div>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="edit({{ $room->id }})">Edit</x-ui.button>
                <x-ui.button type="button" variant="secondary" wire:click="toggle({{ $room->id }})">{{ $room->is_active ? 'Take off sale' : 'Put on sale' }}</x-ui.button>
            </div>
        </article>
    @empty
        @unless ($showForm)
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                No rooms yet. Add each kind of room you have, like "Standard non-AC" and "Deluxe AC".
            </div>
        @endunless
    @endforelse
</div>
