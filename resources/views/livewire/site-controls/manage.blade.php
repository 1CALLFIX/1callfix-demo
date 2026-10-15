<div>
    <h1 class="text-xl font-semibold mb-1">Homepage &amp; sign-up</h1>
    <p class="text-sm text-gray-500 mb-4">
        The first screen of the website, how pictures are shown, the "Services you offer" step of partner sign-up, and how
        many tries sign-up allows. Super Admin only; every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    @php
        $input = 'w-full border rounded px-3 py-2 text-sm';
        $check = 'flex items-start gap-2 text-sm';
    @endphp

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Homepage opener</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" for="sc-headline">Headline</label>
                    <input id="sc-headline" type="text" maxlength="120" wire:model="headline" class="{{ $input }}">
                    @error('headline') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <label class="{{ $check }}"><input type="checkbox" wire:model="showHeadline" class="mt-0.5"> <span>Show the headline above the category icons</span></label>
                <label class="{{ $check }}"><input type="checkbox" wire:model="categoriesFirst" class="mt-0.5"> <span>Category icons first (above the top banner)</span></label>
                <label class="{{ $check }} md:col-span-2"><input type="checkbox" wire:model="showPhotoTiles" class="mt-0.5">
                    <span>Show photo tiles beside the icons (from Home Spotlight). Off keeps the first screen to the icons only.</span></label>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-limit">How many categories to show (4–40)</label>
                    <input id="sc-limit" type="number" min="4" max="40" wire:model="categoryLimit" class="{{ $input }}">
                    @error('categoryLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="sc-colm">Icons per row, phone</label>
                        <select id="sc-colm" wire:model="columnsMobile" class="{{ $input }}">
                            @foreach (range(3, 6) as $n) <option value="{{ $n }}">{{ $n }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" for="sc-cold">Icons per row, computer</label>
                        <select id="sc-cold" wire:model="columnsDesktop" class="{{ $input }}">
                            @foreach (range(4, 12) as $n) <option value="{{ $n }}">{{ $n }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" for="sc-isize">Icon size</label>
                        <select id="sc-isize" wire:model="iconSize" class="{{ $input }}">
                            <option value="small">Small</option>
                            <option value="medium">Medium</option>
                            <option value="large">Large</option>
                        </select>
                    </div>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">Category order and icons come from Catalogue → Categories (sort order and image). With 6 per row on a phone, Small icons fit best.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Pictures</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-fit">How pictures fit their box</label>
                    <select id="sc-fit" wire:model="imageFit" class="{{ $input }}">
                        <option value="contain">Whole picture — never cropped or zoomed</option>
                        <option value="cover">Fill the box — edges may be cut</option>
                    </select>
                </div>
                <div class="space-y-2 pt-1">
                    <label class="{{ $check }}"><input type="checkbox" wire:model="imageBorder" class="mt-0.5"> <span>Thin border around pictures</span></label>
                    <label class="{{ $check }}"><input type="checkbox" wire:model="imageGloss" class="mt-0.5"> <span>Glossy finish (soft highlight and slightly richer colour, no blur)</span></label>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">Applies to category icons, service cards, banners and the photo tiles. For sharp pictures upload at least 400×400 px for icons and 1200 px wide for banners.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Partner sign-up: "Services you offer"</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-svc">Step</label>
                    <select id="sc-svc" wire:model="servicesStep" class="{{ $input }}">
                        <option value="required">Required — at least one service must be ticked</option>
                        <option value="optional">Optional — the applicant may skip it</option>
                        <option value="hidden">Hidden — admins assign services after review</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-svcmax">Most services one applicant may tick (0 = no limit)</label>
                    <input id="sc-svcmax" type="number" min="0" max="50" wire:model="servicesMax" class="{{ $input }}">
                    @error('servicesMax') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">The ticked categories become the provider's assigned categories — the same list job offers are matched on, editable later on the provider's page.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Sign-up attempt limits</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-pf">/partners form: tries per mobile</label>
                    <input id="sc-pf" type="number" min="1" max="100" wire:model="partnerFormPerMobile" class="{{ $input }}">
                    @error('partnerFormPerMobile') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-pfw">…within (minutes)</label>
                    <input id="sc-pfw" type="number" min="1" max="1440" wire:model="partnerFormWindowMinutes" class="{{ $input }}">
                    @error('partnerFormWindowMinutes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="hidden md:block"></div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-otp">Sign-up: code requests per mobile</label>
                    <input id="sc-otp" type="number" min="1" max="50" wire:model="otpPerMobile" class="{{ $input }}">
                    @error('otpPerMobile') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-sub">Sign-up: submit tries per mobile</label>
                    <input id="sc-sub" type="number" min="1" max="100" wire:model="submitPerMobile" class="{{ $input }}">
                    @error('submitPerMobile') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-win">…within (minutes)</label>
                    <input id="sc-win" type="number" min="1" max="1440" wire:model="windowMinutes" class="{{ $input }}">
                    @error('windowMinutes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                Going back and forth between /partners and sign-up in the same browser is never counted as a new try.
                These limits only slow down someone hammering the forms. Firebase's own SMS limit ("too many attempts from this device") is set by Google and is not changed here.
            </p>
        </x-ui.card>

        <x-ui.button type="submit">Save</x-ui.button>
    </form>
</div>
