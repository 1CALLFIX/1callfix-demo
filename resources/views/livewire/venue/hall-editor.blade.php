<?php
    // Owner 2026-11-13: "every step very easy to operate, user friendly, colourful". Five numbered steps, tap choices
    // instead of typing, no technical fields, one big action at the bottom.
    $inp = 'mt-1.5 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-base focus:outline focus:outline-2 focus:outline-blue-600';
    $chip = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border-2 px-4 text-sm font-medium transition';
    $step = fn ($colour) => 'grid h-9 w-9 shrink-0 place-items-center rounded-full text-base font-bold text-white '.$colour;
?>
<div class="space-y-5 pb-24">
    <a href="{{ route('venue.dashboard') }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">&larr; Home</a>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold tracking-tight">{{ $hall ? $hall->name : 'Add your hall' }}</h1>
        @if ($hall)
            <span @class(['rounded-full px-3 py-1 text-sm font-semibold',
                'bg-emerald-100 text-emerald-800' => $hall->status === 'approved', 'bg-amber-100 text-amber-800' => $hall->status === 'in_review',
                'bg-red-100 text-red-700' => $hall->status === 'rejected', 'bg-slate-100 text-slate-700' => $hall->status === 'draft']) data-testid="hall-status">{{ $hall->statusLabel() }}</span>
        @endif
    </div>

    @if ($hall?->status === 'rejected' && $hall->rejection_reason)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please change this and send it again</p>
            <p class="mt-1">{{ $hall->rejection_reason }}</p>
        </div>
    @elseif ($hall?->status === 'in_review')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">⏳ We are checking your hall. You can change it again once we reply, usually within a day.</div>
    @elseif ($hall?->status === 'approved')
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p>✅ Approved. Prices and slots change at once. A new name, address, photos or description show after we check them.</p>
            <button type="button" wire:click="toggleActive" @class(['min-h-11 rounded-xl px-4 font-semibold', 'border border-emerald-300 bg-white text-emerald-800' => $hall->is_active, 'bg-emerald-600 text-white' => ! $hall->is_active]) data-testid="hall-toggle">{{ $hall->is_active ? 'Pause bookings' : 'Start bookings' }}</button>
        </div>
        @if ($hall->pending_changes)
            <div role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="hall-change-waiting">
                <p>⏳ Your change ({{ collect(array_keys($hall->pending_changes))->map(fn ($f) => ['name' => 'name', 'about' => 'description', 'address_line' => 'address', 'lat' => 'location', 'lng' => 'location', 'cover_image' => 'main photo', 'photos' => 'photos'][$f] ?? $f)->unique()->implode(', ') }}) is waiting for our check. Customers see the old details meanwhile.</p>
                <button type="button" wire:click="withdrawChanges" wire:confirm="Withdraw the waiting change?" class="text-sm font-semibold underline">Withdraw</button>
            </div>
        @elseif ($hall->pending_rejection)
            <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">Your last change was not accepted: {{ $hall->pending_rejection }}</div>
        @endif
    @endif

    @error('hall') <div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="hall-error">{{ $message }}</div> @enderror
    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="hall-notice">{{ $notice }}</div> @endif

    <fieldset @disabled(! $editable) class="space-y-5">
        {{-- 1. The hall --}}
        <section class="space-y-4 rounded-2xl border-2 border-blue-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-blue-600') }}">1</span><h2 class="text-lg font-semibold">Your hall</h2></div>
            <div>
                <label for="h-name" class="block text-sm font-medium text-slate-900">Hall name</label>
                <input id="h-name" type="text" wire:model="name" placeholder="As customers will see it" class="{{ $inp }}">
                @error('name') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">What kind of hall?</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Kind of hall">
                    @foreach ($types as $t)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="hallType" value="{{ $t }}" class="sr-only"> {{ $t }}
                        </label>
                    @endforeach
                </div>
                @error('hallType') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="h-seat" class="block text-sm font-medium text-slate-900">How many can sit?</label>
                    <input id="h-seat" type="number" min="10" inputmode="numeric" wire:model="seating" placeholder="e.g. 500" class="{{ $inp }}">
                    @error('seating') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <p class="block text-sm font-medium text-slate-900">AC?</p>
                    <div class="mt-1.5 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="$set('isAc', true)" @class(['min-h-12 rounded-xl border-2 font-semibold', 'border-sky-500 bg-sky-500 text-white' => $isAc, 'border-slate-200 bg-white text-slate-700' => ! $isAc])>❄️ AC</button>
                        <button type="button" wire:click="$set('isAc', false)" @class(['min-h-12 rounded-xl border-2 font-semibold', 'border-slate-700 bg-slate-700 text-white' => ! $isAc, 'border-slate-200 bg-white text-slate-700' => $isAc])>Non-AC</button>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label for="h-float" class="block text-xs font-medium text-slate-700">Standing <span class="text-slate-400">(optional)</span></label>
                    <input id="h-float" type="number" min="10" inputmode="numeric" wire:model="floating" class="{{ $inp }}">
                    @error('floating') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-park" class="block text-xs font-medium text-slate-700">Car parking <span class="text-slate-400">(optional)</span></label>
                    <input id="h-park" type="number" min="0" inputmode="numeric" wire:model="parking" class="{{ $inp }}">
                    @error('parking') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-rooms" class="block text-xs font-medium text-slate-700">Rooms <span class="text-slate-400">(optional)</span></label>
                    <input id="h-rooms" type="number" min="0" inputmode="numeric" wire:model="rooms" class="{{ $inp }}">
                    @error('rooms') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- 2. Slots and prices --}}
        <section class="space-y-4 rounded-2xl border-2 border-emerald-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-emerald-600') }}">2</span><h2 class="text-lg font-semibold">Slots and prices</h2></div>
            <p class="text-sm text-slate-600">Tap to add a slot, then type its price. Change the times only if yours are different.</p>
            <div class="flex flex-wrap gap-2">
                @foreach (['morning' => '☀️ Morning', 'evening' => '🌙 Evening', 'full' => '📅 Full day'] as $k => $label)
                    <button type="button" wire:click="addPreset('{{ $k }}')" class="min-h-11 rounded-full border-2 border-emerald-200 bg-emerald-50 px-4 text-sm font-semibold text-emerald-800 hover:border-emerald-400">+ {{ $label }}</button>
                @endforeach
                @if (count($hallSlots) < \App\Livewire\Venue\HallEditor::MAX_SLOTS)
                    <button type="button" wire:click="addSlot" class="min-h-11 rounded-full border-2 border-dashed border-slate-300 px-4 text-sm font-medium text-slate-600">+ Other</button>
                @endif
            </div>
            @error('hallSlots') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @foreach ($hallSlots as $i => $s)
                <div class="rounded-2xl bg-emerald-50/60 p-3 ring-1 ring-emerald-100" wire:key="slot-{{ $i }}-{{ $s['id'] ?? 'new' }}" data-testid="slot-row">
                    <div class="flex items-center gap-2">
                        <input id="s-name-{{ $i }}" type="text" wire:model="hallSlots.{{ $i }}.name" placeholder="Slot name" aria-label="Slot name" class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3 text-base font-semibold">
                        @if (count($hallSlots) > 1)
                            <button type="button" wire:click="removeSlot({{ $i }})" class="grid h-11 w-11 place-items-center rounded-xl text-xl text-red-600 hover:bg-red-50" aria-label="Remove {{ $s['name'] ?: 'slot' }}">&times;</button>
                        @endif
                    </div>
                    @error('hallSlots.'.$i.'.name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <label class="text-xs font-medium text-slate-600">From
                            <input type="time" wire:model="hallSlots.{{ $i }}.start_time" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 text-base">
                        </label>
                        <label class="text-xs font-medium text-slate-600">To
                            <input type="time" wire:model="hallSlots.{{ $i }}.end_time" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 text-base">
                        </label>
                        <label class="text-xs font-medium text-slate-600">Price ₹
                            <input type="number" min="1" step="1" inputmode="numeric" wire:model="hallSlots.{{ $i }}.price" placeholder="0" class="mt-1 block min-h-11 w-full rounded-xl border-2 border-emerald-300 bg-white px-2 text-base font-bold">
                        </label>
                    </div>
                    @error('hallSlots.'.$i.'.start_time') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                    @error('hallSlots.'.$i.'.end_time') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                    @error('hallSlots.'.$i.'.price') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </div>
            @endforeach
            <div>
                <label for="h-deposit" class="block text-sm font-medium text-slate-900">Refundable deposit ₹ <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="h-deposit" type="number" min="0" step="1" inputmode="numeric" wire:model="deposit" placeholder="e.g. 10000" class="{{ $inp }}">
                <p class="mt-1 text-xs text-slate-500">The customer gets it back after the event if nothing is damaged.</p>
                @error('deposit') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- 3. Photos --}}
        <section class="space-y-4 rounded-2xl border-2 border-fuchsia-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-fuchsia-600') }}">3</span><h2 class="text-lg font-semibold">Photos</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Main photo</p>
                <?php $coverUrl = \App\Models\Hall::imageUrl($hall?->pending_changes['cover_image'] ?? $hall?->cover_image); ?>
                @if ($coverUrl) <img src="{{ $coverUrl }}" alt="Hall cover" class="mt-2 aspect-[3/2] w-full max-w-md rounded-2xl object-cover"> @endif
                <label class="mt-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-fuchsia-300 bg-fuchsia-50 px-4 text-sm font-semibold text-fuchsia-800">
                    📷 {{ $coverUrl ? 'Change main photo' : 'Add main photo' }}
                    <input id="h-cover" type="file" accept="image/jpeg,image/png,image/webp" wire:model="cover" aria-label="Main photo" class="sr-only">
                </label>
                <x-ui.image-size use="hall_photo" />
                <p wire:loading wire:target="cover" class="mt-1 text-sm text-slate-500">Uploading…</p>
                @error('cover') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">More photos <span class="font-normal text-slate-500">(hall, stage, dining, rooms: at least 3)</span></p>
                @if ($photos !== [])
                    <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach ($photos as $i => $p)
                            <li class="relative" wire:key="photo-{{ $i }}-{{ md5($p) }}">
                                <img src="{{ \App\Models\Hall::imageUrl($p) }}" alt="Hall photo {{ $i + 1 }}" class="aspect-square w-full rounded-xl object-cover">
                                <button type="button" wire:click="removePhoto({{ $i }})" class="absolute right-1 top-1 grid h-9 w-9 place-items-center rounded-full bg-white/90 text-red-700 shadow" aria-label="Remove photo {{ $i + 1 }}">&times;</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <label class="mt-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-fuchsia-300 bg-fuchsia-50 px-4 text-sm font-semibold text-fuchsia-800">
                    🖼️ Add photos
                    <input id="h-photos" type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="newPhotos" aria-label="Add photos" class="sr-only">
                </label>
                <x-ui.image-size use="hall_photo" />
                <p wire:loading wire:target="newPhotos" class="mt-1 text-sm text-slate-500">Uploading…</p>
                @error('newPhotos') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('newPhotos.*') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- 4. Where --}}
        <section class="space-y-4 rounded-2xl border-2 border-amber-100 bg-white p-4 sm:p-6" x-data="{ busy: false, err: '' }">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-amber-500') }}">4</span><h2 class="text-lg font-semibold">Where is it?</h2></div>
            <div>
                <label for="h-addr" class="block text-sm font-medium text-slate-900">Full address</label>
                <input id="h-addr" type="text" wire:model="addressLine" placeholder="Door no, street, area, landmark" class="{{ $inp }}">
                @error('addressLine') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <button type="button" x-bind:disabled="busy" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 font-semibold text-amber-900 hover:border-amber-500" data-testid="hall-locate"
                    x-on:click="busy = true; err = ''; navigator.geolocation ? navigator.geolocation.getCurrentPosition(
                        (p) => { $wire.set('lat', String(p.coords.latitude)); $wire.set('lng', String(p.coords.longitude)); busy = false },
                        () => { err = 'Location is off. Allow location for this app and try again.'; busy = false },
                        { enableHighAccuracy: true, timeout: 15000 }) : (err = 'This phone cannot share its location.', busy = false)">
                    📍 <span x-show="busy">Finding you…</span><span x-show="! busy">{{ $lat !== '' ? 'Location saved ✓ (tap to update)' : 'Use my current location' }}</span>
                </button>
                <p class="mt-1 text-xs text-slate-500">Stand at the hall's gate and tap, so customers get the right directions.</p>
                <p x-show="err" x-text="err" class="mt-1.5 text-sm text-red-700"></p>
                @error('lat') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- 5. Facilities, about, rules --}}
        <section class="space-y-4 rounded-2xl border-2 border-teal-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-teal-600') }}">5</span><h2 class="text-lg font-semibold">Facilities and details</h2></div>
            @php
                $tones = [['bg-sky-50 border-sky-100', 'text-sky-800', 'has-[:checked]:border-sky-600 has-[:checked]:bg-sky-600'],
                    ['bg-pink-50 border-pink-100', 'text-pink-800', 'has-[:checked]:border-pink-600 has-[:checked]:bg-pink-600'],
                    ['bg-amber-50 border-amber-100', 'text-amber-800', 'has-[:checked]:border-amber-600 has-[:checked]:bg-amber-600'],
                    ['bg-emerald-50 border-emerald-100', 'text-emerald-800', 'has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-600'],
                    ['bg-violet-50 border-violet-100', 'text-violet-800', 'has-[:checked]:border-violet-600 has-[:checked]:bg-violet-600'],
                    ['bg-teal-50 border-teal-100', 'text-teal-800', 'has-[:checked]:border-teal-600 has-[:checked]:bg-teal-600']];
            @endphp
            <div>
                <p class="text-sm font-medium text-slate-900">Tap what your hall has</p>
                <p class="text-xs text-slate-500">Everything is free unless you mark it Paid below.</p>
                <div class="mt-2 space-y-3">
                    @foreach ($amenityGroups as $gi => $g)
                        @php [$box, $head, $on] = $tones[$gi % count($tones)]; @endphp
                        <div class="rounded-xl border p-3 {{ $box }}" wire:key="fac-{{ $gi }}">
                            @if ($g['title'] !== '') <p class="text-sm font-semibold {{ $head }}">{{ $g['title'] }}</p> @endif
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($g['items'] as $a)
                                    <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 {{ $on }} has-[:checked]:text-white">
                                        <input type="checkbox" wire:model.live="amenities" value="{{ $a }}" class="sr-only"> {{ $a }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @if ($amenities !== [])
                <div class="rounded-xl border border-slate-200 p-3" data-testid="facility-charges">
                    <p class="text-sm font-medium text-slate-900">Free or paid?</p>
                    <p class="text-xs text-slate-500">Tap Paid for anything the customer pays extra for. The price is optional.</p>
                    <ul class="mt-2 divide-y divide-slate-100">
                        @foreach (array_values(array_unique($amenities)) as $a)
                            @php $k = \App\Livewire\Venue\HallEditor::key($a); @endphp
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="charge-{{ $k }}">
                                <span class="text-sm text-slate-800">{{ $a }}</span>
                                <span class="flex items-center gap-2">
                                    <button type="button" wire:click="$set('paid.{{ $k }}', false)" @class(['min-h-11 rounded-full border-2 px-4 text-sm font-semibold', 'border-emerald-600 bg-emerald-600 text-white' => empty($paid[$k]), 'border-slate-200 bg-white text-slate-600' => ! empty($paid[$k])])>Free</button>
                                    <button type="button" wire:click="$set('paid.{{ $k }}', true)" @class(['min-h-11 rounded-full border-2 px-4 text-sm font-semibold', 'border-amber-500 bg-amber-500 text-white' => ! empty($paid[$k]), 'border-slate-200 bg-white text-slate-600' => empty($paid[$k])])>Paid</button>
                                    @if (! empty($paid[$k]))
                                        <input type="text" inputmode="decimal" wire:model="paidPrice.{{ $k }}" placeholder="₹ price (optional)" class="min-h-11 w-36 rounded-xl border border-slate-300 px-3 text-sm">
                                    @endif
                                </span>
                                @error('paidPrice.'.$k) <p class="w-full text-sm text-red-700">{{ $message }}</p> @enderror
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($outsideOptions !== [])
                <div class="rounded-xl border border-orange-100 bg-orange-50 p-3" data-testid="outside-policy">
                    <p class="text-sm font-semibold text-orange-900">Can customers bring these from outside?</p>
                    <p class="text-xs text-orange-800">Customers see your answers before they book.</p>
                    <ul class="mt-2 space-y-2">
                        @foreach ($outsideOptions as $o)
                            @php $k = \App\Livewire\Venue\HallEditor::key($o); $ans = $outside[$k] ?? ''; @endphp
                            <li class="rounded-lg bg-white p-2" wire:key="out-{{ $k }}">
                                <p class="text-sm font-medium text-slate-800">{{ $o }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    @foreach (['yes' => ['Allowed', 'border-emerald-600 bg-emerald-600'], 'fee' => ['Allowed with a fee', 'border-amber-500 bg-amber-500'], 'no' => ['Not allowed', 'border-red-600 bg-red-600']] as $v => [$label, $onCls])
                                        <button type="button" wire:click="$set('outside.{{ $k }}', '{{ $ans === $v ? '' : $v }}')" @class(['min-h-11 rounded-full border-2 px-4 text-sm font-semibold', $onCls.' text-white' => $ans === $v, 'border-slate-200 bg-white text-slate-600' => $ans !== $v])>{{ $label }}</button>
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div>
                <label for="h-about" class="block text-sm font-medium text-slate-900">About the hall <span class="font-normal text-slate-500">(a few lines)</span></label>
                <textarea id="h-about" wire:model="about" rows="3" placeholder="Good for weddings and receptions. Dining for 300. Near the bus stand." class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-base"></textarea>
                @error('about') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">Hall rules <span class="font-normal text-slate-500">(tap the ones you follow)</span></p>
                <div class="mt-2 flex flex-wrap gap-2" data-testid="rule-chips">
                    @foreach ($ruleOptions as $r)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-rose-600 has-[:checked]:bg-rose-600 has-[:checked]:text-white">
                            <input type="checkbox" wire:model="ruleList" value="{{ $r }}" class="sr-only"> {{ $r }}
                        </label>
                    @endforeach
                </div>
                <label for="h-rules" class="mt-3 block text-sm font-medium text-slate-900">Anything else? <span class="font-normal text-slate-500">(optional, your own words)</span></label>
                <textarea id="h-rules" wire:model="rules" rows="2" placeholder="e.g. Band and baraat only on the road side." class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2.5 text-base"></textarea>
                @error('rules') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="h-gst" class="block text-sm font-medium text-slate-900">GST number <span class="font-normal text-slate-500">(only if you have one)</span></label>
                <input id="h-gst" type="text" wire:model="gstNumber" class="{{ $inp }} uppercase">
                @error('gstNumber') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>
    </fieldset>

    @if ($editable)
        <div class="fixed inset-x-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur" style="bottom: calc(4rem + env(safe-area-inset-bottom, 0px));">
            <div class="mx-auto flex max-w-5xl items-center gap-2">
                @if ($hall === null || $hall->isEditable())
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="hall-send-for-review">Send for review ✓</button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800">Save</button>
                @else
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="hall-save">Save changes</button>
                @endif
                @if ($canDelete)
                    <button type="button" wire:click="deleteDraft" wire:confirm="Delete this draft hall?" class="min-h-12 rounded-xl px-3 text-sm text-red-700 hover:bg-red-50">Delete</button>
                @endif
            </div>
        </div>
    @endif
</div>
