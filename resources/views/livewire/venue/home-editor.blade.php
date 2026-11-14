<?php
    // Property for rent (owner 2026-11-14): six numbered, coloured steps; taps from Rental Settings lists for this area;
    // the owner types only the rent, the size, a landmark and the address. One big Save bar at the bottom.
    $inp = 'mt-1.5 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-base focus:outline focus:outline-2 focus:outline-blue-600';
    $chip = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border-2 px-4 text-sm font-medium transition';
    $step = fn ($colour) => 'grid h-9 w-9 shrink-0 place-items-center rounded-full text-base font-bold text-white '.$colour;
    $err = fn ($f) => $errors->has($f) ? '<p class="mt-1.5 text-sm text-red-700">'.e($errors->first($f)).'</p>' : '';
    $tones = [['bg-sky-50 border-sky-100', 'text-sky-800', 'has-[:checked]:border-sky-600 has-[:checked]:bg-sky-600'],
        ['bg-emerald-50 border-emerald-100', 'text-emerald-800', 'has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-600'],
        ['bg-amber-50 border-amber-100', 'text-amber-800', 'has-[:checked]:border-amber-600 has-[:checked]:bg-amber-600'],
        ['bg-violet-50 border-violet-100', 'text-violet-800', 'has-[:checked]:border-violet-600 has-[:checked]:bg-violet-600'],
        ['bg-pink-50 border-pink-100', 'text-pink-800', 'has-[:checked]:border-pink-600 has-[:checked]:bg-pink-600'],
        ['bg-teal-50 border-teal-100', 'text-teal-800', 'has-[:checked]:border-teal-600 has-[:checked]:bg-teal-600']];
?>
<div class="space-y-5 pb-24">
    <a href="{{ route('venue.dashboard') }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">&larr; Home</a>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold tracking-tight">{{ $home ? $home->title : 'Add your property for rent' }}</h1>
        @if ($home)
            <span @class(['rounded-full px-3 py-1 text-sm font-semibold',
                'bg-emerald-100 text-emerald-800' => $home->status === 'approved', 'bg-amber-100 text-amber-800' => $home->status === 'in_review',
                'bg-red-100 text-red-700' => $home->status === 'rejected', 'bg-slate-100 text-slate-700' => $home->status === 'draft']) data-testid="home-status">{{ $home->statusLabel() }}</span>
        @endif
    </div>

    @if ($home?->status === 'rejected' && $home->rejection_reason)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please change this and send it again</p>
            <p class="mt-1">{{ $home->rejection_reason }}</p>
        </div>
    @elseif ($home?->status === 'in_review')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">⏳ We are checking your property. You can change it again once we reply, usually within a day.</div>
    @elseif ($home?->status === 'approved')
        <div class="space-y-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" data-testid="home-live-box">
            @if ($home->rented_at)
                <p>🔑 Marked as rented on {{ $home->rented_at->format('j M Y') }}. Customers do not see it.</p>
            @elseif ($home->expires_at && $home->expires_at->isPast())
                <p>⏰ Hidden since {{ $home->expires_at->format('j M Y') }}. Is it still free? Tap "Still available".</p>
            @else
                <p>✅ Customers see your property{{ $home->expires_at ? ' until '.$home->expires_at->format('j M Y') : '' }}. Rent and details change at once; new photos, description or address show after we check them.</p>
            @endif
            <div class="flex flex-wrap gap-2">
                @if (! $home->rented_at)
                    <button type="button" wire:click="markRented" wire:confirm="Mark this property as rented? Customers will stop seeing it." class="min-h-11 rounded-xl border border-emerald-300 bg-white px-4 font-semibold text-emerald-800" data-testid="home-rented">🔑 Rented out</button>
                @endif
                <button type="button" wire:click="stillAvailable" class="min-h-11 rounded-xl bg-emerald-600 px-4 font-semibold text-white" data-testid="home-available">{{ $home->rented_at ? 'Available again' : 'Still available' }}</button>
            </div>
        </div>
        @if ($home->pending_changes)
            <div role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p>⏳ Your change ({{ collect(array_keys($home->pending_changes))->map(fn ($f) => ['about' => 'description', 'address_line' => 'address', 'landmark' => 'landmark', 'lat' => 'location', 'lng' => 'location', 'cover_image' => 'main photo', 'photos' => 'photos'][$f] ?? $f)->unique()->implode(', ') }}) is waiting for our check.</p>
                <button type="button" wire:click="withdrawChanges" wire:confirm="Withdraw the waiting change?" class="text-sm font-semibold underline">Withdraw</button>
            </div>
        @elseif ($home->pending_rejection)
            <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">Your last change was not accepted: {{ $home->pending_rejection }}</div>
        @endif
    @endif

    @error('home') <div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="home-error">{{ $message }}</div> @enderror
    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="home-notice">{{ $notice }}</div> @endif

    <fieldset @disabled(! $editable) class="space-y-5">
        {{-- 1. The home --}}
        <section class="space-y-4 rounded-2xl border-2 border-blue-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-blue-600') }}">1</span><h2 class="text-lg font-semibold">Your property</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">What kind of property?</p>
                <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-blue-700">🏠 To live in</p>
                <div class="mt-1.5 flex flex-wrap gap-2" role="radiogroup" aria-label="Home to live in">
                    @foreach ($types as $t)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                            <input type="radio" wire:model.live="homeType" value="{{ $t }}" class="sr-only"> {{ $t }}
                        </label>
                    @endforeach
                </div>
                @if ($businessTypes !== [])
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-orange-700">🏪 For business</p>
                    <div class="mt-1.5 flex flex-wrap gap-2" role="radiogroup" aria-label="For business" data-testid="business-kinds">
                        @foreach ($businessTypes as $t)
                            <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-500 has-[:checked]:text-white">
                                <input type="radio" wire:model.live="homeType" value="{{ $t }}" class="sr-only"> {{ $t }}
                            </label>
                        @endforeach
                    </div>
                @endif
                {!! $err('homeType') !!}
            </div>
            <div @class(['hidden' => $business])>
                <p class="text-sm font-medium text-slate-900">Size</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Size">
                    @foreach ($sizes as $t)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="size" value="{{ $t }}" class="sr-only"> {{ $t }}
                        </label>
                    @endforeach
                </div>
                {!! $err('size') !!}
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">Furnishing</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Furnishing">
                    @foreach ($furnishings as $t)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="furnishing" value="{{ $t }}" class="sr-only"> {{ $t }}
                        </label>
                    @endforeach
                </div>
                {!! $err('furnishing') !!}
            </div>
            @if ($floors !== [])
                <div>
                    <p class="text-sm font-medium text-slate-900">Floor <span class="font-normal text-slate-500">(optional)</span></p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($floors as $t)
                            <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                                <input type="radio" wire:model="floor" value="{{ $t }}" class="sr-only"> {{ $t }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($facings !== [])
                <div>
                    <p class="text-sm font-medium text-slate-900">Facing <span class="font-normal text-slate-500">(optional)</span></p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($facings as $t)
                            <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                                <input type="radio" wire:model="facing" value="{{ $t }}" class="sr-only"> {{ $t }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div>
                <label for="hm-area" class="block text-sm font-medium text-slate-900">Floor area <span class="font-normal text-slate-500">({{ $areaUnit }}, optional)</span></label>
                <input id="hm-area" type="number" inputmode="numeric" min="10" wire:model="area" class="{{ $inp }} max-w-xs">
                {!! $err('area') !!}
            </div>
        </section>

        {{-- 2. Rent --}}
        <section class="space-y-4 rounded-2xl border-2 border-emerald-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-emerald-600') }}">2</span><h2 class="text-lg font-semibold">Rent</h2></div>
            <div>
                <label for="hm-rent" class="block text-sm font-medium text-slate-900">Rent per month ({{ $symbol }})</label>
                <input id="hm-rent" type="number" inputmode="numeric" min="0" wire:model.live.debounce.400ms="rent" class="{{ $inp }} max-w-xs text-lg font-semibold" data-testid="home-rent">
                {!! $err('rent') !!}
            </div>
            <div>
                <label for="hm-deposit" class="block text-sm font-medium text-slate-900">Deposit ({{ $symbol }}) <span class="font-normal text-slate-500">(tap months or type)</span></label>
                @if ($depositMonths !== [])
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($depositMonths as $m)
                            <button type="button" wire:click="depositMonths({{ $m }})" @disabled($rentValue <= 0) class="min-h-11 rounded-full border-2 border-emerald-200 bg-emerald-50 px-4 text-sm font-semibold text-emerald-800 hover:border-emerald-500 disabled:opacity-40">{{ $m }} {{ $m === 1 ? 'month' : 'months' }}</button>
                        @endforeach
                    </div>
                @endif
                <input id="hm-deposit" type="number" inputmode="numeric" min="0" wire:model="deposit" class="{{ $inp }} max-w-xs">
                {!! $err('deposit') !!}
            </div>
            <div>
                <label for="hm-maint" class="block text-sm font-medium text-slate-900">Maintenance per month ({{ $symbol }}) <span class="font-normal text-slate-500">(optional, leave empty if included)</span></label>
                <input id="hm-maint" type="number" inputmode="numeric" min="0" wire:model="maintenance" class="{{ $inp }} max-w-xs">
                {!! $err('maintenance') !!}
            </div>
            <div>
                <label for="hm-from" class="block text-sm font-medium text-slate-900">Ready to move in from</label>
                <div class="flex flex-wrap items-center gap-2">
                    <input id="hm-from" type="date" wire:model="availableFrom" class="{{ $inp }} max-w-xs">
                    <button type="button" wire:click="readyNow" class="mt-1.5 min-h-12 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 text-sm font-semibold text-emerald-800">Ready now</button>
                </div>
                {!! $err('availableFrom') !!}
            </div>
        </section>

        {{-- 3. Who can rent --}}
        <section class="space-y-4 rounded-2xl border-2 border-violet-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-violet-600') }}">3</span><h2 class="text-lg font-semibold">Who do you rent to?</h2></div>
            <div class="flex flex-wrap gap-2" data-testid="tenant-chips">
                @foreach ($tenantChoices as $t)
                    <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-violet-600 has-[:checked]:bg-violet-600 has-[:checked]:text-white">
                        <input type="checkbox" wire:model="tenants" value="{{ $t }}" class="sr-only"> {{ $t }}
                    </label>
                @endforeach
            </div>
            {!! $err('tenants') !!}
        </section>

        {{-- 4. Where --}}
        <section class="space-y-4 rounded-2xl border-2 border-amber-100 bg-white p-4 sm:p-6" x-data="{ busy: false, err: '' }">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-amber-500') }}">4</span><h2 class="text-lg font-semibold">Where is it?</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Area</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Area">
                    @foreach ($zones as $z)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-500 has-[:checked]:text-white">
                            <input type="radio" wire:model.live="zoneId" value="{{ $z->id }}" class="sr-only"> {{ $z->name }}
                        </label>
                    @endforeach
                </div>
                {!! $err('zoneId') !!}
            </div>
            <div>
                <label for="hm-landmark" class="block text-sm font-medium text-slate-900">Landmark <span class="font-normal text-slate-500">(everyone sees this, e.g. "Near RTC bus stand")</span></label>
                <input id="hm-landmark" type="text" wire:model="landmark" maxlength="160" class="{{ $inp }}">
                {!! $err('landmark') !!}
            </div>
            <div>
                <label for="hm-addr" class="block text-sm font-medium text-slate-900">Full address <span class="font-normal text-slate-500">(shown only to people whose visit you accept)</span></label>
                <input id="hm-addr" type="text" wire:model="addressLine" placeholder="Door no, street, area" class="{{ $inp }}">
                {!! $err('addressLine') !!}
            </div>
            <div>
                <button type="button" x-bind:disabled="busy" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 font-semibold text-amber-900 hover:border-amber-500" data-testid="home-locate"
                    x-on:click="busy = true; err = ''; navigator.geolocation ? navigator.geolocation.getCurrentPosition(
                        (p) => { $wire.set('lat', String(p.coords.latitude)); $wire.set('lng', String(p.coords.longitude)); busy = false },
                        () => { err = 'Location is off. Allow location for this app and try again.'; busy = false },
                        { enableHighAccuracy: true, timeout: 15000 }) : (err = 'This phone cannot share its location.', busy = false)">
                    📍 <span x-show="busy">Finding you…</span><span x-show="! busy">{{ $lat !== '' ? 'Location saved ✓ (tap to update)' : 'Use my current location' }}</span>
                </button>
                <p class="mt-1 text-xs text-slate-500">Stand at the gate and tap. Only visitors you accept get directions.</p>
                <p x-show="err" x-text="err" class="mt-1.5 text-sm text-red-700"></p>
            </div>
        </section>

        {{-- 5. Facilities and rules --}}
        <section class="space-y-4 rounded-2xl border-2 border-teal-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-teal-600') }}">5</span><h2 class="text-lg font-semibold">Facilities and rules</h2></div>
            <p class="text-sm font-medium text-slate-900">Tap what your property has</p>
            <div class="space-y-3">
                @foreach ($amenityGroups as $gi => $g)
                    @php [$box, $head, $on] = $tones[$gi % count($tones)]; @endphp
                    <div class="rounded-xl border p-3 {{ $box }}" wire:key="hfac-{{ $gi }}">
                        @if ($g['title'] !== '') <p class="text-sm font-semibold {{ $head }}">{{ $g['title'] }}</p> @endif
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($g['items'] as $a)
                                <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 {{ $on }} has-[:checked]:text-white">
                                    <input type="checkbox" wire:model="amenities" value="{{ $a }}" class="sr-only"> {{ $a }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">House rules <span class="font-normal text-slate-500">(tap the ones you follow)</span></p>
                <div class="mt-2 flex flex-wrap gap-2" data-testid="home-rule-chips">
                    @foreach ($ruleChoices as $r)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-rose-600 has-[:checked]:bg-rose-600 has-[:checked]:text-white">
                            <input type="checkbox" wire:model="ruleList" value="{{ $r }}" class="sr-only"> {{ $r }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <label for="hm-about" class="block text-sm font-medium text-slate-900">About the property <span class="font-normal text-slate-500">(a few lines, optional)</span></label>
                <textarea id="hm-about" wire:model="about" rows="3" placeholder="Quiet street, 5 minutes to the bus stand. Water all day." class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-base"></textarea>
                {!! $err('about') !!}
            </div>
        </section>

        {{-- 6. Photos --}}
        <section class="space-y-4 rounded-2xl border-2 border-fuchsia-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-fuchsia-600') }}">6</span><h2 class="text-lg font-semibold">Photos</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Main photo</p>
                <?php $coverUrl = \App\Models\HouseListing::imageUrl($home?->pending_changes['cover_image'] ?? $home?->cover_image); ?>
                @if ($coverUrl) <img src="{{ $coverUrl }}" alt="Property cover" class="mt-2 aspect-[3/2] w-full max-w-md rounded-2xl object-cover"> @endif
                <label class="mt-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-fuchsia-300 bg-fuchsia-50 px-4 text-sm font-semibold text-fuchsia-800">
                    📷 {{ $coverUrl ? 'Change main photo' : 'Add main photo' }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" wire:model="cover" aria-label="Main photo" class="sr-only">
                </label>
                <x-ui.image-size use="hall_photo" />
                <p wire:loading wire:target="cover" class="mt-1 text-sm text-slate-500">Uploading…</p>
                {!! $err('cover') !!}
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">More photos <span class="font-normal text-slate-500">(rooms, kitchen, bathroom, front, outside{{ $minPhotos > 0 ? ': at least '.$minPhotos : '' }})</span></p>
                @if ($photos !== [])
                    <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach ($photos as $i => $p)
                            <li class="relative" wire:key="hphoto-{{ $i }}-{{ md5($p) }}">
                                <img src="{{ \App\Models\HouseListing::imageUrl($p) }}" alt="Property photo {{ $i + 1 }}" class="aspect-square w-full rounded-xl object-cover">
                                <button type="button" wire:click="removePhoto({{ $i }})" class="absolute right-1 top-1 grid h-9 w-9 place-items-center rounded-full bg-white/90 text-red-700 shadow" aria-label="Remove photo {{ $i + 1 }}">&times;</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <label class="mt-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-fuchsia-300 bg-fuchsia-50 px-4 text-sm font-semibold text-fuchsia-800">
                    🖼️ Add photos
                    <input type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="newPhotos" aria-label="Add photos" class="sr-only">
                </label>
                <x-ui.image-size use="hall_photo" />
                <p wire:loading wire:target="newPhotos" class="mt-1 text-sm text-slate-500">Uploading…</p>
                {!! $err('newPhotos') !!}
                {!! $err('newPhotos.*') !!}
            </div>
        </section>
    </fieldset>

    @if ($editable)
        <div class="fixed inset-x-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur" style="bottom: calc(4rem + env(safe-area-inset-bottom, 0px));">
            <div class="mx-auto flex max-w-5xl items-center gap-2">
                @if ($home === null || $home->isEditable())
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="home-send-for-review">Send for review ✓</button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800">Save</button>
                @else
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="home-save">Save changes</button>
                @endif
            </div>
        </div>
    @endif
</div>
