<div>
    <h1 class="text-xl font-semibold mb-1">Homepage sections</h1>
    <p class="text-sm text-gray-500 mb-4">
        What the homepage shows and in what order. The words are on
        <a href="{{ route('admin.site-text') }}" class="text-blue-700 underline">Website text &amp; language</a> (Homepage).
        A door or card shows only where its business is switched on in Modules, and a section with nothing real to show stays hidden.
        Super Admin only; every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700" data-testid="home-sections-flash">{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-1">Page order</h2>
            <p class="text-xs text-gray-500 mb-3">
                Top to bottom, as customers see it. Untick to hide a section.
                @unless ($orderSaved)
                    Until you change the order here, it follows the older position settings on Homepage &amp; sign-up.
                @else
                    This order now decides where the doors, the top banner and the parcel card sit.
                @endunless
            </p>
            <ol class="divide-y rounded border" data-testid="page-order">
                @foreach ($order as $i => $section)
                    <li class="flex items-center gap-3 px-3 py-2 text-sm" wire:key="order-{{ $section }}" data-section="{{ $section }}">
                        <span class="w-6 text-right text-xs text-gray-400">{{ $i + 1 }}</span>
                        <label class="flex flex-1 items-center gap-2">
                            <input type="checkbox" wire:model="sectionShow.{{ $section }}">
                            <span>{{ $sectionLabels[$section] }}</span>
                        </label>
                        <button type="button" wire:click="moveUp('{{ $section }}')" @disabled($loop->first)
                                class="rounded border px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move {{ $sectionLabels[$section] }} up">↑</button>
                        <button type="button" wire:click="moveDown('{{ $section }}')" @disabled($loop->last)
                                class="rounded border px-2 py-0.5 text-xs disabled:opacity-30" aria-label="Move {{ $sectionLabels[$section] }} down">↓</button>
                    </li>
                @endforeach
            </ol>
            <p class="mt-2 text-xs text-gray-500">Moves take effect when you press Save.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-1">Business cards</h2>
            <p class="text-xs text-gray-500 mb-3">
                One card per business: a heading, "View all", a row of icons and one offer line with "More". Category cards use
                the category's subcategories as icons (or its services when it has fewer than two). Parcel and Shop cards show
                only where they are on. With no offer line typed, a card shows a real fact (a live offer, shops open now, the
                parcel price) or nothing.
            </p>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium mb-1" for="hc-icons">Icons per card</label>
                    <select id="hc-icons" wire:model="cardIcons" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="4">4 (one row)</option>
                        <option value="8">8 (two rows on a phone)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="hc-limit">How many category cards (0–20)</label>
                    <input id="hc-limit" type="number" min="0" max="20" wire:model="cardCategoryLimit" class="w-full border rounded px-3 py-2 text-sm">
                    @error('cardCategoryLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="hc-new">"New" badge for icons added in the last … days (0 = off)</label>
                    <input id="hc-new" type="number" min="0" max="365" wire:model="cardNewDays" class="w-full border rounded px-3 py-2 text-sm">
                    @error('cardNewDays') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500">
                            <th class="py-2 pr-3">Card</th><th class="py-2 pr-3">Show</th><th class="py-2 pr-3">Order</th>
                            <th class="py-2 pr-3">Offer line (English)</th><th class="py-2">Offer line (Telugu)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($cards as $card => $c)
                            <tr wire:key="card-admin-{{ $card }}">
                                <td class="py-2 pr-3 font-medium">{{ $c['title'] }}</td>
                                <td class="py-2 pr-3"><input type="checkbox" wire:model="cardShow.{{ $card }}" aria-label="Show {{ $c['title'] }}"></td>
                                <td class="py-2 pr-3">
                                    <input type="number" min="1" max="999" wire:model="cardOrder.{{ $card }}" class="w-20 border rounded px-2 py-1" aria-label="Order of {{ $c['title'] }}">
                                    @error('cardOrder.'.$card) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="py-2 pr-3">
                                    <input type="text" maxlength="120" wire:model="cardLineEn.{{ $card }}" class="w-64 border rounded px-2 py-1" placeholder="e.g. Flat ₹100 off on first booking" aria-label="English offer line for {{ $c['title'] }}">
                                    @error('cardLineEn.'.$card) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="py-2">
                                    <input type="text" maxlength="120" wire:model="cardLineTe.{{ $card }}" class="w-64 border rounded px-2 py-1" placeholder="తెలుగులో (ఖాళీగా ఉంటే English)" aria-label="Telugu offer line for {{ $c['title'] }}">
                                    @error('cardLineTe.'.$card) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Lower order numbers come first. Only type an offer that is really on: the online-payment rule for benefits still applies at checkout.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">"Explore 1CallFix" doors</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="show" class="mt-0.5"> <span>Show the doors row</span></label>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="showPromise" class="mt-0.5"> <span>Show the line under the heading</span></label>
                <label class="flex items-start gap-2 text-sm md:col-span-2"><input type="checkbox" wire:model="liveInfo" class="mt-0.5">
                    <span>Live numbers on the doors (services listed, shops open now, parcel price). Real numbers only; a zero shows the plain line.</span></label>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                @foreach ($doors as $door => $d)
                    <div class="rounded border p-3" wire:key="door-admin-{{ $door }}">
                        <div class="flex items-center gap-3">
                            <img src="{{ $d['image'] }}" alt="" class="h-14 w-14 rounded object-contain bg-gray-50">
                            <p class="text-sm font-semibold">{{ $d['title'] }}</p>
                        </div>
                        <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="doorShow.{{ $door }}"> Show this door</label>
                        <label class="mt-2 block text-xs text-gray-500" for="hs-order-{{ $door }}">Order (1 = first)</label>
                        <input id="hs-order-{{ $door }}" type="number" min="1" max="99" wire:model="doorOrder.{{ $door }}" class="w-24 border rounded px-2 py-1 text-sm">
                        @error('doorOrder.'.$door) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        <label class="mt-2 block text-xs text-gray-500" for="hs-img-{{ $door }}">Picture (PNG, JPG or WebP, max 2 MB, square works best)</label>
                        <input id="hs-img-{{ $door }}" type="file" accept="image/png,image/jpeg,image/webp" wire:model="doorImage.{{ $door }}" class="text-xs">
                        @error('doorImage.'.$door) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        @if ($d['uploaded'])
                            <button type="button" wire:click="resetPicture('{{ $door }}')" class="mt-2 text-xs text-blue-700 underline">Use the built-in picture</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <div class="flex justify-end">
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
        </div>
    </form>
</div>
