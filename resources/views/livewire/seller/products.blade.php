<div class="space-y-4" x-data x-on:seller-scroll-top.window="$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))">
    {{-- 2026-11-03 (owner, live walk-through): the form and the "Saved." message are at the top of the page; a seller
         who pressed Edit or Save far down the list never saw them. The page now moves up to them. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">{{ $isRestaurant ? 'Menu' : 'Products' }}</h1>
        @unless ($creating)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('seller.products.add-many') }}" wire:navigate class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border-2 border-blue-600 px-3 text-sm font-bold text-blue-700 hover:bg-blue-50" data-testid="add-many"><x-icon name="photo" class="h-4 w-4" /> Add many (list or photo)</a>
                <x-ui.button wire:click="startCreate">{{ $isRestaurant ? 'Add a dish' : 'Add a product' }}</x-ui.button>
            </div>
        @endunless
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    @if ($creating)
        <x-ui.card>
            <h2 class="text-lg font-semibold">{{ $isRestaurant ? ($editingId ? 'Edit dish' : 'New dish') : ($editingId ? 'Edit product' : 'New product') }}</h2>
            @if ($editingPending !== '')
                <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Your new {{ mb_strtolower($editingPending) }} {{ str_contains($editingPending, ',') ? 'are' : 'is' }} waiting for approval (shown below). Customers see the old ones until then. Saving again replaces the waiting change.</p>
            @elseif ($editingId)
                <p class="mt-1 text-xs text-slate-500">Price, discount, stock and "show to customers" change at once. A new name, photos, description or category is checked by 1CallFix first; customers see the old ones until then.</p>
            @endif
            <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Name</span>
                    <input type="text" wire:model="name" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="What customers search for">
                    @error('name') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Description <span class="font-normal text-slate-500">(optional)</span></span>
                    <textarea rows="3" wire:model="description" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-base" placeholder="{{ $isRestaurant ? 'Ingredients, spice level, serves how many' : 'Size, material, what'.chr(39).'s in the box' }}"></textarea>
                    @error('description') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Category</span>
                    <select wire:model="categoryId" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                        <option value="">Choose…</option>
                        @foreach (\App\Models\MarketplaceCategory::grouped($categories) as $c)<option value="{{ $c->id }}">{{ $c->parent_id ? '— ' : '' }}{{ $c->name }}</option>@endforeach
                    </select>
                    @error('categoryId') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Price ({{ $currencySymbol }})</span>
                    <input type="text" inputmode="decimal" wire:model="price" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    @error('price') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Discount % <span class="font-normal text-slate-500">(optional)</span></span>
                    <input type="text" inputmode="decimal" wire:model="discountPercent" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    <span class="text-xs text-slate-500">Discounted items are paid online only.</span>
                    @error('discountPercent') <span class="block text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                @if ($isRestaurant)
                    <fieldset class="block text-sm">
                        <legend class="font-medium">Veg or non-veg</legend>
                        <div class="mt-1 flex flex-wrap gap-2">
                            @foreach ($foodTypes as $value => $label)
                                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50">
                                    <input type="radio" wire:model="foodType" value="{{ $value }}"> <x-food-mark :type="$value" /> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('foodType') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                    </fieldset>
                    <fieldset class="block text-sm">
                        <legend class="font-medium">Add-on choices</legend>
                        @forelse ($addOnGroups as $g)
                            <label class="mt-1 flex items-center gap-2"><input type="checkbox" wire:model="groupIds" value="{{ $g->id }}" class="rounded"> {{ $g->name }} <span class="text-xs text-slate-500">({{ $g->ruleText() }})</span></label>
                        @empty
                            <p class="mt-1 text-xs text-slate-500">None yet. Make them on the <a href="{{ route('seller.add-ons') }}" wire:navigate class="underline">Add-ons</a> page (for example "Choose your base", "Extra toppings").</p>
                        @endforelse
                        @error('groupIds.*') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                    </fieldset>
                @endif
                @if ($editingHasChoices && ! $isRestaurant)
                    <div class="text-sm">
                        <span class="font-medium">In stock</span>
                        <p class="mt-1 text-slate-600">{{ $stock }} in all · set per choice on <a href="{{ route('seller.products.choices', $editingId) }}" wire:navigate class="font-medium text-blue-700">Units &amp; choices</a>.</p>
                    </div>
                @else
                <label @class(['block text-sm', 'hidden' => $isRestaurant])>
                    <span class="font-medium">In stock</span>
                    <input type="text" inputmode="numeric" wire:model="stock" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    @error('stock') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                @endif
                <label class="block text-sm">
                    <span class="font-medium">Limit per order <span class="font-normal text-slate-500">(optional)</span></span>
                    <input type="text" inputmode="numeric" wire:model="maxCartQuantity" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    @error('maxCartQuantity') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="isActive" class="h-5 w-5 rounded border-slate-300"> <span class="font-medium">Show to customers</span>
                </label>

                <div class="sm:col-span-2">
                    <p class="text-sm font-medium">Photos <span class="font-normal text-slate-500">(up to {{ $maxPhotos }})</span></p>
                    {{-- 2026-11-03 (owner): a clear "Add photo" box with a picture icon instead of the browser's "Choose Files". --}}
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ($photos as $i => $path)
                            <div class="relative" wire:key="photo-{{ $i }}">
                                <img src="{{ str_contains($path, '://') ? $path : \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" alt="" class="h-24 w-24 rounded-xl border object-cover">
                                @if ($i === 0)<span class="absolute bottom-1 left-1 rounded bg-slate-900/75 px-1.5 text-[10px] font-semibold text-white">Main</span>@endif
                                <button type="button" wire:click="removePhoto({{ $i }})" class="absolute -right-2 -top-2 grid h-7 w-7 place-items-center rounded-full bg-red-600 text-sm font-bold text-white shadow" aria-label="Remove photo {{ $i + 1 }}">×</button>
                            </div>
                        @endforeach
                        @foreach ($uploads as $i => $file)
                            <div class="relative" wire:key="upload-{{ $i }}">
                                @if (method_exists($file, 'isPreviewable') && $file->isPreviewable())
                                    <img src="{{ $file->temporaryUrl() }}" alt="" class="h-24 w-24 rounded-xl border object-cover">
                                @else
                                    <span class="grid h-24 w-24 place-items-center rounded-xl border bg-slate-50 text-xs text-slate-500">New photo</span>
                                @endif
                                @if (count($photos) === 0 && $i === 0)<span class="absolute bottom-1 left-1 rounded bg-slate-900/75 px-1.5 text-[10px] font-semibold text-white">Main</span>@endif
                                <button type="button" wire:click="removeUpload({{ $i }})" class="absolute -right-2 -top-2 grid h-7 w-7 place-items-center rounded-full bg-red-600 text-sm font-bold text-white shadow" aria-label="Remove new photo {{ $i + 1 }}">×</button>
                            </div>
                        @endforeach
                        @if (count($photos) + count($uploads) < $maxPhotos)
                            <label class="flex h-24 w-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-blue-300 bg-blue-50/50 text-blue-700 transition hover:border-blue-500 hover:bg-blue-50" data-testid="add-photo">
                                <x-icon name="photo" class="h-7 w-7" />
                                <span class="text-xs font-bold">Add photo</span>
                                <input type="file" wire:model="newUploads" multiple accept="image/jpeg,image/png,image/webp" class="sr-only">
                            </label>
                        @endif
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">JPG, PNG or WEBP, up to 4 MB each. The first photo is the main one customers see.</p>
                    <div wire:loading wire:target="newUploads" class="text-xs font-medium text-blue-700">Uploading…</div>
                    @error('newUploads.*') <span class="block text-sm text-red-700">{{ $message }}</span> @enderror
                    @error('uploads') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                    @error('uploads.*') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </div>

                <div class="flex gap-2 sm:col-span-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled">{{ $editingId ? 'Save changes' : 'Add product' }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="cancel">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @unless ($creating)
        {{-- 2026-11-03: change many products in Excel: download, change, upload. --}}
        <details class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm" @if ($sheetErrors) open @endif>
            <summary class="cursor-pointer font-semibold text-slate-800">Change many in Excel</summary>
            <p class="mt-2 text-slate-600">Download your products, change the price, discount, stock or show (yes / no) in Excel, save as CSV and upload it here. Add a row with an empty id for a new product. In "photos" put up to 5 direct photo links separated by | (from ImgBB or postimg.cc use the "Direct link", which starts with https://i.ibb.co/ or https://i.postimg.cc/). A new name or photo is checked by 1CallFix first.</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="downloadSheet">Download my products</x-ui.button>
                <label class="inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-lg border border-slate-300 px-3 font-medium text-slate-800 hover:bg-slate-50">
                    <span>{{ $sheet ? 'File chosen' : 'Choose the file' }}</span>
                    <input type="file" wire:model="sheet" accept=".csv,text/csv" class="sr-only">
                </label>
                @if ($sheet)<x-ui.button type="button" wire:click="uploadSheet" wire:loading.attr="disabled">Upload changes</x-ui.button>@endif
            </div>
            @error('sheet') <p class="mt-2 text-red-700">{{ $message }}</p> @enderror
            @if ($sheetErrors)
                <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-red-700" data-testid="sheet-errors">
                    @foreach (array_slice($sheetErrors, 0, 30) as $e)<li>{{ $e }}</li>@endforeach
                    @if (count($sheetErrors) > 30)<li>and {{ count($sheetErrors) - 30 }} more</li>@endif
                </ul>
            @endif
        </details>
    @endunless

    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ ($isRestaurant ?? false) ? 'Search your menu' : 'Search your products' }}" class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">

    <div class="space-y-2">
        @forelse ($products as $p)
            <x-ui.card class="!p-3" wire:key="product-{{ $p->id }}">
                <div class="flex items-center gap-3">
                    @if ($url = ($p->image_urls[0] ?? null))
                        <img src="{{ $url }}" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover">
                    @else
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-400">No photo</div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">@if ($isRestaurant && $p->food_type)<x-food-mark :type="$p->food_type" class="mr-1" />@endif{{ $p->name }}</p>
                        <p class="text-sm text-slate-600">
                            {{ $currencySymbol }}{{ number_format($p->effective_price, 2) }}
                            @if ($p->discount_percent)<span class="text-xs text-slate-400 line-through">{{ $currencySymbol }}{{ number_format((float) $p->price, 2) }}</span>@endif
                            @if ($p->category) · {{ $p->category->name }}@endif
                            @if ($p->unitLabel()) · {{ $p->unitLabel() }}@endif
                        </p>
                        @if ($p->option_names)
                            <p class="text-xs text-slate-500">Choices: {{ implode(', ', $p->option_names) }} · {{ $p->active_variants_count }} on sale</p>
                        @endif
                        <p class="text-xs">
                            @unless ($p->is_approved)<span class="font-semibold text-amber-700">Awaiting approval</span> @endunless
                            @unless ($p->is_active)<span class="font-semibold text-slate-500">Hidden</span>@endunless
                            @if ($isRestaurant && $p->isSoldOutNow())<span class="font-semibold text-red-700">Sold out today</span>@endif
                        </p>
                        {{-- Owner rule 2026-11-03: a change to a live item waits; customers see the old details meanwhile. --}}
                        @if ($c = $latestChanges[$p->id] ?? null)
                            @if ($c->status === \App\Models\PendingChange::PENDING)
                                <p class="text-xs font-semibold text-amber-700" data-testid="change-waiting">New {{ mb_strtolower($c->summary()) }} waiting for approval · customers see the old ones</p>
                            @elseif ($c->status === \App\Models\PendingChange::REJECTED)
                                <p class="text-xs font-semibold text-red-700" data-testid="change-rejected">Change not approved: {{ $c->review_note }}</p>
                            @endif
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        @if ($isRestaurant)
                            <button type="button" wire:click="toggleSoldOut({{ $p->id }})" @class(['min-h-9 rounded-full border px-3 text-xs font-semibold',
                                'border-red-300 bg-red-50 text-red-800' => $p->isSoldOutNow(), 'border-slate-300 text-slate-700' => ! $p->isSoldOutNow()])>{{ $p->isSoldOutNow() ? 'Sold out — make available' : 'Sold out today' }}</button>
                        @elseif ($p->option_names)
                            <a href="{{ route('seller.products.choices', $p->id) }}" wire:navigate @class(['text-xs', 'text-red-700' => $p->stock <= 0, 'text-slate-500' => $p->stock > 0])>Stock {{ $p->stock }} (by choice)</a>
                        @else
                            <label class="flex items-center gap-1 text-xs text-slate-500">Stock
                                <input type="text" inputmode="numeric" value="{{ $p->stock }}" wire:change="setStock({{ $p->id }}, $event.target.value)"
                                       @class(['w-16 rounded border px-2 py-1 text-right text-sm', 'border-red-400 text-red-700' => $p->stock <= 0, 'border-slate-300' => $p->stock > 0])>
                            </label>
                        @endif
                        <div class="flex gap-3 text-xs">
                            <button type="button" wire:click="edit({{ $p->id }})" class="font-medium text-blue-700">Edit</button>
                            <a href="{{ route('seller.products.choices', $p->id) }}" wire:navigate class="font-medium text-blue-700">Units &amp; choices</a>
                            <button type="button" wire:click="toggleActive({{ $p->id }})" class="font-medium text-slate-600">{{ $p->is_active ? 'Hide' : 'Show' }}</button>
                            <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Remove {{ $p->name }}?" class="font-medium text-red-700">Remove</button>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-slate-600">{{ $search ? 'No products match.' : 'No products yet. Add your first one.' }}</p></x-ui.card>
        @endforelse
    </div>

    {{ $products->links() }}
</div>
