<?php $inp = 'mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600'; ?>
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Your hotel</h1>
        <p class="mt-1 text-sm text-slate-600">What guests see before they book.</p>
    </div>

    @if ($notice)
        <div role="status" class="rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    {{-- ── photos ── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-500">Photos</h2>

        <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
            @if ($hotel->cover_image_url)
                <figure class="relative aspect-[3/2] overflow-hidden rounded-lg bg-slate-100">
                    <img src="{{ $hotel->cover_image_url }}" alt="Cover photo" class="h-full w-full object-cover" loading="lazy">
                    <figcaption class="absolute left-1 top-1 rounded bg-slate-900/80 px-1.5 text-[10px] font-semibold text-white">Cover</figcaption>
                </figure>
            @endif
            @foreach ($photoUrls as $i => $url)
                <figure class="relative aspect-[3/2] overflow-hidden rounded-lg bg-slate-100">
                    <img src="{{ $url }}" alt="Hotel photo {{ $i + 1 }}" class="h-full w-full object-cover" loading="lazy">
                    <button type="button" wire:click="removePhoto({{ $i }})" wire:confirm="Remove this photo?"
                            class="absolute right-1 top-1 flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-sm font-bold text-slate-700 shadow" aria-label="Remove photo {{ $i + 1 }}">×</button>
                </figure>
            @endforeach
        </div>

        <form wire:submit="savePhotos" class="mt-4 space-y-3">
            <div>
                <label for="h-cover" class="block text-sm font-medium text-slate-900">{{ $hotel->cover_image ? 'Change the cover photo' : 'Cover photo (the front of your hotel)' }}</label>
                <input id="h-cover" type="file" accept="image/jpeg,image/png,image/webp" wire:model="cover" class="mt-1.5 block w-full text-sm">
                <x-ui.image-size use="hotel_photo" />
                @error('cover') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="h-photos" class="block text-sm font-medium text-slate-900">More photos (reception, rooms, bathroom, view)</label>
                <input id="h-photos" type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="newPhotos" class="mt-1.5 block w-full text-sm">
                <x-ui.image-size use="hotel_photo" />
                @error('newPhotos') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('newPhotos.*') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="savePhotos,cover,newPhotos">Upload photos</x-ui.button>
        </form>
    </section>

    {{-- ── details ── --}}
    <form wire:submit="save" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
        <h2 class="text-sm font-semibold uppercase text-slate-500">Details</h2>
        <div>
            <label for="h-name" class="block text-sm font-medium text-slate-900">Hotel name</label>
            <input id="h-name" type="text" wire:model="name" class="{{ $inp }}">
            @error('name') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="h-about" class="block text-sm font-medium text-slate-900">About your hotel</label>
            <textarea id="h-about" rows="4" wire:model="description" class="{{ $inp }}" placeholder="Near the bus stand, 5 minutes from the railway station. Clean AC rooms with hot water."></textarea>
            @error('description') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="h-address" class="block text-sm font-medium text-slate-900">Address</label>
            <input id="h-address" type="text" wire:model="addressLine" class="{{ $inp }}">
            @error('addressLine') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div x-data="{ busy: false, err: '' }">
            <p class="block text-sm font-medium text-slate-900">Map pin</p>
            <p class="mt-1 text-xs text-slate-500">Stand at your hotel's entrance and tap the button, so guests and cabs find you.</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ui.button type="button" variant="secondary" x-bind:disabled="busy"
                    x-on:click="busy = true; err = ''; navigator.geolocation ? navigator.geolocation.getCurrentPosition(
                        (p) => { $wire.set('lat', String(p.coords.latitude)); $wire.set('lng', String(p.coords.longitude)); busy = false },
                        () => { err = 'Location is off. Allow location for this app and try again.'; busy = false },
                        { enableHighAccuracy: true, timeout: 15000 }) : (err = 'This phone cannot share its location.', busy = false)">
                    Use my current location
                </x-ui.button>
                <span class="text-xs text-slate-500">{{ $lat }}, {{ $lng }}</span>
            </div>
            <p x-show="err" x-text="err" class="mt-1.5 text-sm text-red-700"></p>
            @error('lat') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label for="h-in" class="block text-sm font-medium text-slate-900">Check-in from</label>
                <input id="h-in" type="time" wire:model="checkInFrom" class="{{ $inp }}">
                @error('checkInFrom') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="h-out" class="block text-sm font-medium text-slate-900">Check-out by</label>
                <input id="h-out" type="time" wire:model="checkOutBy" class="{{ $inp }}">
                @error('checkOutBy') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label for="h-phone" class="block text-sm font-medium text-slate-900">Reception phone</label>
                <input id="h-phone" type="tel" inputmode="tel" wire:model="contactPhone" class="{{ $inp }}">
                @error('contactPhone') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="h-gst" class="block text-sm font-medium text-slate-900">GST number (if you have one)</label>
                <input id="h-gst" type="text" maxlength="15" wire:model="gstNumber" class="{{ $inp }} uppercase">
                @error('gstNumber') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>
        <div>
            <label for="h-rules" class="block text-sm font-medium text-slate-900">House rules</label>
            <textarea id="h-rules" rows="3" wire:model="policies" class="{{ $inp }}" placeholder="ID needed at check-in. No smoking in rooms."></textarea>
            @error('policies') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        @if ($allFacilities !== [])
            <fieldset>
                <legend class="text-sm font-medium text-slate-900">Facilities</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($allFacilities as $f)
                        <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-full border border-slate-300 px-3 text-sm has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50">
                            <input type="checkbox" value="{{ $f }}" wire:model="facilities" class="h-4 w-4"> {{ $f }}
                        </label>
                    @endforeach
                </div>
                @error('facilities.*') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </fieldset>
        @endif
        <x-ui.button type="submit" size="lg" class="w-full sm:w-auto" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
    </form>
</div>
