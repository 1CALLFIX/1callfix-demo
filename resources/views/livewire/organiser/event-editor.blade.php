<div class="space-y-5">
    <a href="{{ route('organiser.events') }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">&larr; Your events</a>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold tracking-tight">{{ $event ? $event->title : 'New event' }}</h1>
        @if ($event)
            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700" data-testid="event-status">{{ $event->statusLabel() }}</span>
        @endif
    </div>

    @if ($event?->status === 'rejected' && $event->rejection_reason)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please change this and send it again</p>
            <p class="mt-1">{{ $event->rejection_reason }}</p>
        </div>
    @elseif ($event?->status === 'in_review')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">We are checking this event. You will see the result here, usually within a day.</div>
    @elseif ($event?->status === 'approved')
        <div role="status" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">Approved. Tickets go on sale when Events opens in your city. To change anything now, call us.</div>
    @endif

    @error('event') <div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="event-error">{{ $message }}</div> @enderror
    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="event-notice">{{ $notice }}</div> @endif

    <fieldset @disabled(! $editable) class="space-y-5">
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">The event</h2>
            <div>
                <label for="e-title" class="block text-sm font-medium text-slate-900">Event name</label>
                <input id="e-title" type="text" wire:model="title" placeholder="e.g. Year End Bash 2026" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                @error('title') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="e-cat" class="block text-sm font-medium text-slate-900">Kind of event</label>
                    <select id="e-cat" wire:model="category" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base">
                        <option value="">Choose one</option>
                        @foreach ($categories as $c) <option value="{{ $c }}">{{ $c }}</option> @endforeach
                    </select>
                    @error('category') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="e-age" class="block text-sm font-medium text-slate-900">Age limit</label>
                    <select id="e-age" wire:model="minAge" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base">
                        <option value="">All ages</option>
                        <option value="18">18 and above</option>
                        <option value="21">21 and above</option>
                    </select>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="e-start" class="block text-sm font-medium text-slate-900">Starts</label>
                    <input id="e-start" type="datetime-local" wire:model="startsAt" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('startsAt') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="e-end" class="block text-sm font-medium text-slate-900">Ends</label>
                    <input id="e-end" type="datetime-local" wire:model="endsAt" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('endsAt') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="e-desc" class="block text-sm font-medium text-slate-900">About the event</label>
                <textarea id="e-desc" wire:model="description" rows="5" placeholder="Who is performing, what is included (food, drinks, parking), dress code..." class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base"></textarea>
                @error('description') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Venue</h2>
            <div>
                <label for="e-venue" class="block text-sm font-medium text-slate-900">Venue name</label>
                <input id="e-venue" type="text" wire:model="venueName" placeholder="e.g. Grand Convention Hall" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                @error('venueName') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="e-addr" class="block text-sm font-medium text-slate-900">Venue address</label>
                <input id="e-addr" type="text" wire:model="venueAddress" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                @error('venueAddress') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="e-lat" class="block text-sm font-medium text-slate-900">Map latitude <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="e-lat" type="text" inputmode="decimal" wire:model="lat" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('lat') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="e-lng" class="block text-sm font-medium text-slate-900">Map longitude <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="e-lng" type="text" inputmode="decimal" wire:model="lng" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                    @error('lng') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Cover photo</h2>
            @if ($event?->coverUrl())
                <img src="{{ $event->coverUrl() }}" alt="Event cover" class="aspect-video w-full rounded-xl object-cover">
            @endif
            <input id="e-cover" type="file" accept="image/jpeg,image/png,image/webp" wire:model="cover" aria-label="Cover photo" class="block w-full text-sm">
            <x-ui.image-size use="event_cover" />
            @error('cover') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </section>

        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <div>
                <h2 class="font-semibold">Tickets</h2>
                <p class="mt-1 text-sm text-slate-600">Each kind of ticket has its own price and number. A "Couple" ticket can let 2 people in. Up to {{ $maxPerOrder }} tickets in one order.</p>
            </div>
            @error('tickets') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @foreach ($tickets as $i => $t)
                <div class="space-y-3 rounded-xl border border-slate-200 p-3" wire:key="ticket-{{ $i }}-{{ $t['id'] ?? 'new' }}" data-testid="ticket-row">
                    <div class="flex items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <label class="block text-sm font-medium text-slate-900" for="t-name-{{ $i }}">Ticket name</label>
                            <input id="t-name-{{ $i }}" type="text" wire:model="tickets.{{ $i }}.name" placeholder="Early Bird, Regular, VIP..." class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                        </div>
                        @if (count($tickets) > 1)
                            <button type="button" wire:click="removeTicket({{ $i }})" class="min-h-11 rounded-lg px-3 text-sm text-red-700 hover:bg-red-50">Remove</button>
                        @endif
                    </div>
                    @error('tickets.'.$i.'.name') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="t-price-{{ $i }}">Price (₹)</label>
                            <input id="t-price-{{ $i }}" type="number" min="1" step="1" wire:model="tickets.{{ $i }}.price" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                            @error('tickets.'.$i.'.price') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="t-qty-{{ $i }}">How many</label>
                            <input id="t-qty-{{ $i }}" type="number" min="1" step="1" wire:model="tickets.{{ $i }}.quantity" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                            @error('tickets.'.$i.'.quantity') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="t-admits-{{ $i }}">People per ticket</label>
                            <input id="t-admits-{{ $i }}" type="number" min="1" max="10" step="1" wire:model="tickets.{{ $i }}.admits" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                            @error('tickets.'.$i.'.admits') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="t-max-{{ $i }}">Most per order</label>
                            <input id="t-max-{{ $i }}" type="number" min="1" max="{{ $maxPerOrder }}" step="1" wire:model="tickets.{{ $i }}.max_per_order" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                            @error('tickets.'.$i.'.max_per_order') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="t-desc-{{ $i }}">What it includes <span class="font-normal text-slate-500">(optional)</span></label>
                            <input id="t-desc-{{ $i }}" type="text" wire:model="tickets.{{ $i }}.description" placeholder="e.g. Entry + 1 drink" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700" for="t-end-{{ $i }}">Sales end <span class="font-normal text-slate-500">(optional, e.g. Early Bird)</span></label>
                            <input id="t-end-{{ $i }}" type="datetime-local" wire:model="tickets.{{ $i }}.sale_ends_at" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-base">
                            @error('tickets.'.$i.'.sale_ends_at') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endforeach
            @if (count($tickets) < \App\Livewire\Organiser\EventEditor::MAX_TICKET_TYPES)
                <button type="button" wire:click="addTicket" class="min-h-11 rounded-lg border border-dashed border-slate-300 px-4 text-sm font-medium text-slate-700 hover:border-blue-400">+ Add another ticket type</button>
            @endif
        </section>

        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <label for="e-terms" class="block font-semibold">Rules for ticket holders <span class="text-sm font-normal text-slate-500">(optional)</span></label>
            <textarea id="e-terms" wire:model="terms" rows="3" placeholder="e.g. Carry a photo ID. No outside food. Entry closes at 11 PM." class="block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base"></textarea>
            @error('terms') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        </section>
    </fieldset>

    @if ($editable)
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button wire:click="submit" wire:loading.attr="disabled" wire:target="submit,save,cover" data-testid="send-for-review">Send for review</x-ui.button>
            <x-ui.button variant="secondary" wire:click="save" wire:loading.attr="disabled" wire:target="submit,save,cover">Save draft</x-ui.button>
            @if ($event?->status === 'draft')
                <button type="button" wire:click="deleteDraft" wire:confirm="Delete this draft?" class="ml-auto text-sm text-red-700 hover:underline">Delete draft</button>
            @endif
        </div>
    @endif
</div>
