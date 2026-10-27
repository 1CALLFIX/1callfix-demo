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
            <h2 class="text-sm font-semibold mb-1">Parcel card</h2>
            <p class="text-xs text-gray-500 mb-3">Shown only where Parcel is switched on (Admin → Modules). The price is the area's base fare from Parcel Settings.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-ppos">Where the card sits</label>
                    <select id="sc-ppos" wire:model="parcelPosition" class="{{ $input }}">
                        <option value="top">Top — above the category icons</option>
                        <option value="after_categories">After the category icons</option>
                        <option value="after_banner">After the top banner</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-pcol">Card colour</label>
                    <select id="sc-pcol" wire:model="parcelColour" class="{{ $input }}">
                        <option value="blue">Blue</option>
                        <option value="amber">Yellow</option>
                        <option value="emerald">Green</option>
                        <option value="slate">Dark</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-ptitle">Title (also the icon's name)</label>
                    <input id="sc-ptitle" type="text" maxlength="40" wire:model="parcelTitle" class="{{ $input }}">
                    @error('parcelTitle') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-psub">Subtitle</label>
                    <input id="sc-psub" type="text" maxlength="80" wire:model="parcelSubtitle" class="{{ $input }}">
                    @error('parcelSubtitle') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" for="sc-pchips">Chips on the card (separate with commas)</label>
                    <input id="sc-pchips" type="text" maxlength="160" wire:model="parcelChips" class="{{ $input }}">
                    @error('parcelChips') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-psend">Send button</label>
                    <input id="sc-psend" type="text" maxlength="30" wire:model="parcelSendLabel" class="{{ $input }}">
                    @error('parcelSendLabel') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-ptrack">Track button</label>
                    <input id="sc-ptrack" type="text" maxlength="30" wire:model="parcelTrackLabel" class="{{ $input }}">
                    @error('parcelTrackLabel') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-pbadge">Label on the Parcel icon</label>
                    <input id="sc-pbadge" type="text" maxlength="10" wire:model="parcelBadgeText" class="{{ $input }}">
                    @error('parcelBadgeText') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-2 pt-1">
                    <label class="{{ $check }}"><input type="checkbox" wire:model="parcelGridTile" class="mt-0.5"> <span>Show a Parcel icon as the first category icon</span></label>
                    <label class="{{ $check }}"><input type="checkbox" wire:model="parcelNewBadge" class="mt-0.5"> <span>Show the label on the Parcel icon</span></label>
                    <label class="{{ $check }}"><input type="checkbox" wire:model="parcelShowChips" class="mt-0.5"> <span>Show the chips</span></label>
                    <label class="{{ $check }}"><input type="checkbox" wire:model="parcelShowPrice" class="mt-0.5"> <span>Show "from ₹…" on the card</span></label>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-phead">/parcel page heading</label>
                    <input id="sc-phead" type="text" maxlength="60" wire:model="parcelPageHeading" class="{{ $input }}">
                    @error('parcelPageHeading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-pintro">/parcel page line under the heading</label>
                    <input id="sc-pintro" type="text" maxlength="200" wire:model="parcelPageIntro" class="{{ $input }}">
                    @error('parcelPageIntro') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                @foreach ([['image', 'Card picture', 'parcelImageFile', $parcelImageUrl, $parcelImageUploaded], ['icon', 'Parcel icon', 'parcelIconFile', $parcelIconUrl, $parcelIconUploaded]] as [$which, $label, $field, $url, $uploaded])
                    <div>
                        <p class="block text-sm font-medium mb-1">{{ $label }}</p>
                        <div class="flex items-center gap-3">
                            <img src="{{ $url }}" alt="" class="h-14 w-20 rounded border bg-blue-50 object-contain">
                            <div class="space-y-1">
                                <input type="file" accept="image/png,image/jpeg,image/webp" wire:model="{{ $field }}" class="text-xs">
                                @if ($uploaded)
                                    <button type="button" wire:click="resetParcelPicture('{{ $which }}')" class="text-xs text-red-600 underline">Use the built-in one</button>
                                @else
                                    <p class="text-xs text-gray-400">Built-in drawing in use.</p>
                                @endif
                            </div>
                        </div>
                        @error($field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-3">PNG, JPEG or WebP, up to 2 MB, saved with the Save button. A transparent background looks best: about 800×550 px for the card picture, 400×400 px for the icon.</p>
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

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Partner review</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-ramber">Wait turns amber after (hours)</label>
                    <input id="sc-ramber" type="number" min="1" max="720" wire:model="reviewAmberHours" class="{{ $input }}">
                    @error('reviewAmberHours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sc-rred">Overdue (red) after (hours)</label>
                    <input id="sc-rred" type="number" min="1" max="720" wire:model="reviewRedHours" class="{{ $input }}">
                    @error('reviewRedHours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                Counted from the partner's oldest document still waiting. Overdue partners get a red row and a pulsing "Overdue" badge on Admin → Partners and Providers,
                a red count on the Needs review tab, and a red dot on Partners in the menu. Each franchise sees only its own partners.
            </p>
        </x-ui.card>

        <x-ui.button type="submit">Save</x-ui.button>
    </form>
</div>
