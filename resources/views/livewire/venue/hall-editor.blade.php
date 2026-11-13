<div class="space-y-5">
    <a href="{{ route('venue.dashboard') }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">&larr; Home</a>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold tracking-tight">{{ $hall ? $hall->name : 'Add a hall' }}</h1>
        @if ($hall)
            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700" data-testid="hall-status">{{ $hall->statusLabel() }}</span>
        @endif
    </div>

    @if ($hall?->status === 'rejected' && $hall->rejection_reason)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please change this and send it again</p>
            <p class="mt-1">{{ $hall->rejection_reason }}</p>
        </div>
    @elseif ($hall?->status === 'in_review')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">We are checking this hall. You can change it again once we reply, usually within a day.</div>
    @elseif ($hall?->status === 'approved')
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p>Approved. Prices, slots and facilities change at once. A new name, address, photos or description show after we check them.</p>
            <button type="button" wire:click="toggleActive" class="min-h-11 rounded-lg border border-emerald-300 bg-white px-3 font-semibold" data-testid="hall-toggle">{{ $hall->is_active ? 'Switch off bookings' : 'Switch on bookings' }}</button>
        </div>
        @if ($hall->pending_changes)
            <div role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="hall-change-waiting">
                <p>Your change ({{ collect(array_keys($hall->pending_changes))->map(fn ($f) => ['name' => 'name', 'about' => 'description', 'address_line' => 'address', 'lat' => 'map pin', 'lng' => 'map pin', 'cover_image' => 'cover photo', 'photos' => 'photos'][$f] ?? $f)->unique()->implode(', ') }}) is waiting for our check. Customers see the old details meanwhile.</p>
                <button type="button" wire:click="withdrawChanges" wire:confirm="Withdraw the waiting change?" class="text-sm font-semibold underline">Withdraw</button>
            </div>
        @elseif ($hall->pending_rejection)
            <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">Your last change was not accepted: {{ $hall->pending_rejection }}</div>
        @endif
    @endif

    @error('hall') <div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="hall-error">{{ $message }}</div> @enderror
    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="hall-notice">{{ $notice }}</div> @endif

    <fieldset @disabled(! $editable) class="space-y-5">
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">The hall</h2>
            <div>
                <label for="h-name" class="block text-sm font-medium text-slate-900">Hall name</label>
                <input id="h-name" type="text" wire:model="name" placeholder="As customers will see it" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                @error('name') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="h-type" class="block text-sm font-medium text-slate-900">Kind of hall</label>
                    <select id="h-type" wire:model="hallType" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base">
                        <option value="">Choose one</option>
                        @foreach ($types as $t) <option value="{{ $t }}">{{ $t }}</option> @endforeach
                    </select>
                    @error('hallType') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <label class="flex min-h-11 items-center gap-3 self-end rounded-lg border border-slate-300 px-3 text-sm">
                    <input type="checkbox" wire:model="isAc" class="h-5 w-5 rounded border-slate-300"> Air-conditioned (AC)
                </label>
            </div>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <label for="h-seat" class="block text-sm font-medium text-slate-900">Seating</label>
                    <input id="h-seat" type="number" min="10" inputmode="numeric" wire:model="seating" placeholder="e.g. 500" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('seating') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-float" class="block text-sm font-medium text-slate-900">Standing <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="h-float" type="number" min="10" inputmode="numeric" wire:model="floating" placeholder="e.g. 800" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('floating') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-park" class="block text-sm font-medium text-slate-900">Car parking</label>
                    <input id="h-park" type="number" min="0" inputmode="numeric" wire:model="parking" placeholder="cars" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('parking') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-rooms" class="block text-sm font-medium text-slate-900">Rooms</label>
                    <input id="h-rooms" type="number" min="0" inputmode="numeric" wire:model="rooms" placeholder="guest rooms" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('rooms') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="h-about" class="block text-sm font-medium text-slate-900">About the hall</label>
                <textarea id="h-about" wire:model="about" rows="4" placeholder="What it is good for (weddings, receptions, birthdays), dining, decoration, nearby landmarks..." class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base"></textarea>
                @error('about') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Facilities</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($amenityList as $a)
                    <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 px-3 text-sm">
                        <input type="checkbox" wire:model="amenities" value="{{ $a }}" class="h-5 w-5 rounded border-slate-300"> {{ $a }}
                    </label>
                @endforeach
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <div>
                <h2 class="font-semibold">Slots and prices</h2>
                <p class="mt-1 text-sm text-slate-600">What customers book: a full day, or a morning and an evening. Each has its own time and price. A slot that ends after midnight is fine (e.g. 6 PM – 2 AM).</p>
            </div>
            @error('hallSlots') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @foreach ($hallSlots as $i => $s)
                <div class="space-y-3 rounded-xl border border-slate-200 p-3" wire:key="slot-{{ $i }}-{{ $s['id'] ?? 'new' }}" data-testid="slot-row">
                    <div class="flex items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <label class="block text-sm font-medium text-slate-900" for="s-name-{{ $i }}">Slot name</label>
                            <input id="s-name-{{ $i }}" type="text" wire:model="hallSlots.{{ $i }}.name" placeholder="Full day, Morning, Evening..." class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                        </div>
                        @if (count($hallSlots) > 1)
                            <button type="button" wire:click="removeSlot({{ $i }})" class="min-h-11 rounded-lg px-3 text-sm text-red-700 hover:bg-red-50">Remove</button>
                        @endif
                    </div>
                    @error('hallSlots.'.$i.'.name') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="s-start-{{ $i }}">From</label>
                            <input id="s-start-{{ $i }}" type="time" wire:model="hallSlots.{{ $i }}.start_time" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-2 py-2 text-base">
                            @error('hallSlots.'.$i.'.start_time') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="s-end-{{ $i }}">To</label>
                            <input id="s-end-{{ $i }}" type="time" wire:model="hallSlots.{{ $i }}.end_time" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-2 py-2 text-base">
                            @error('hallSlots.'.$i.'.end_time') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="s-price-{{ $i }}">Price (₹)</label>
                            <input id="s-price-{{ $i }}" type="number" min="1" step="1" inputmode="numeric" wire:model="hallSlots.{{ $i }}.price" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-2 py-2 text-base">
                            @error('hallSlots.'.$i.'.price') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endforeach
            @if (count($hallSlots) < \App\Livewire\Venue\HallEditor::MAX_SLOTS)
                <button type="button" wire:click="addSlot" class="min-h-11 rounded-lg border border-dashed border-slate-300 px-4 text-sm font-medium text-slate-700 hover:border-blue-400">+ Add another slot</button>
            @endif
            <div class="grid gap-4 border-t pt-4 sm:grid-cols-2">
                <div>
                    <label for="h-deposit" class="block text-sm font-medium text-slate-900">Refundable deposit (₹) <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="h-deposit" type="number" min="0" step="1" inputmode="numeric" wire:model="deposit" placeholder="e.g. 10000" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    <p class="mt-1 text-xs text-slate-500">Paid with the balance and returned to the customer after the event unless there is damage.</p>
                    @error('deposit') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-gst" class="block text-sm font-medium text-slate-900">GST number <span class="font-normal text-slate-500">(if you have one)</span></label>
                    <input id="h-gst" type="text" wire:model="gstNumber" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base uppercase">
                    @error('gstNumber') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Address</h2>
            <div>
                <label for="h-addr" class="block text-sm font-medium text-slate-900">Full address</label>
                <input id="h-addr" type="text" wire:model="addressLine" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                @error('addressLine') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="h-lat" class="block text-sm font-medium text-slate-900">Map latitude <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="h-lat" type="text" inputmode="decimal" wire:model="lat" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('lat') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-lng" class="block text-sm font-medium text-slate-900">Map longitude <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="h-lng" type="text" inputmode="decimal" wire:model="lng" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('lng') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Photos</h2>
            <div>
                <p class="text-sm font-medium text-slate-900">Cover photo</p>
                @php($coverUrl = \App\Models\Hall::imageUrl($hall?->pending_changes['cover_image'] ?? $hall?->cover_image))
                @if ($coverUrl) <img src="{{ $coverUrl }}" alt="Hall cover" class="mt-2 aspect-video w-full max-w-md rounded-xl object-cover"> @endif
                <input id="h-cover" type="file" accept="image/jpeg,image/png,image/webp" wire:model="cover" aria-label="Cover photo" class="mt-2 block w-full text-sm">
                <x-ui.image-size use="hall_photo" />
                @error('cover') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">More photos <span class="font-normal text-slate-500">(the hall, stage, dining, rooms, parking: up to {{ \App\Models\Hall::MAX_PHOTOS }})</span></p>
                @if ($photos !== [])
                    <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach ($photos as $i => $p)
                            <li class="relative" wire:key="photo-{{ $i }}-{{ md5($p) }}">
                                <img src="{{ \App\Models\Hall::imageUrl($p) }}" alt="Hall photo {{ $i + 1 }}" class="aspect-square w-full rounded-lg object-cover">
                                <button type="button" wire:click="removePhoto({{ $i }})" class="absolute right-1 top-1 grid h-8 w-8 place-items-center rounded-full bg-white/90 text-red-700 shadow" aria-label="Remove photo {{ $i + 1 }}">&times;</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <input id="h-photos" type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="newPhotos" aria-label="Add photos" class="mt-2 block w-full text-sm">
                <x-ui.image-size use="hall_photo" />
                @error('newPhotos') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('newPhotos.*') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <label for="h-rules" class="block font-semibold">Hall rules <span class="text-sm font-normal text-slate-500">(optional)</span></label>
            <textarea id="h-rules" wire:model="rules" rows="3" placeholder="e.g. Music till 10 PM. No fireworks inside. Decoration by our team or yours." class="block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base"></textarea>
            @error('rules') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        </section>
    </fieldset>

    @if ($editable)
        <div class="flex flex-wrap items-center gap-3">
            @if ($hall === null || $hall->isEditable())
                <x-ui.button wire:click="submit" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" data-testid="hall-send-for-review">Send for review</x-ui.button>
                <x-ui.button variant="secondary" wire:click="save" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos">Save draft</x-ui.button>
            @else
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save,cover,newPhotos" data-testid="hall-save">Save changes</x-ui.button>
            @endif
            @if ($canDelete)
                <button type="button" wire:click="deleteDraft" wire:confirm="Delete this draft hall?" class="ml-auto text-sm text-red-700 hover:underline">Delete draft</button>
            @endif
        </div>
    @endif
</div>
