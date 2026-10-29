<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Products</h1>
        @unless ($creating)
            <x-ui.button wire:click="startCreate">Add a product</x-ui.button>
        @endunless
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    @if ($creating)
        <x-ui.card>
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Edit product' : 'New product' }}</h2>
            <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Name</span>
                    <input type="text" wire:model="name" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="What customers search for">
                    @error('name') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Description <span class="font-normal text-slate-500">(optional)</span></span>
                    <textarea rows="3" wire:model="description" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-base" placeholder="Size, material, what's in the box"></textarea>
                    @error('description') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Category</span>
                    <select wire:model="categoryId" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                        <option value="">Choose…</option>
                        @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->parent_id ? '— ' : '' }}{{ $c->name }}</option>@endforeach
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
                <label class="block text-sm">
                    <span class="font-medium">In stock</span>
                    <input type="text" inputmode="numeric" wire:model="stock" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                    @error('stock') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
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
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($photos as $i => $path)
                            <div class="relative" wire:key="photo-{{ $i }}">
                                <img src="{{ str_contains($path, '://') ? $path : \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" alt="" class="h-20 w-20 rounded-lg border object-cover">
                                <button type="button" wire:click="removePhoto({{ $i }})" class="absolute -right-1 -top-1 rounded-full bg-red-600 px-1.5 text-xs text-white" aria-label="Remove photo">×</button>
                            </div>
                        @endforeach
                        @foreach ($uploads as $i => $file)
                            @if (method_exists($file, 'isPreviewable') && $file->isPreviewable())
                                <img src="{{ $file->temporaryUrl() }}" alt="" class="h-20 w-20 rounded-lg border object-cover" wire:key="upload-{{ $i }}">
                            @endif
                        @endforeach
                    </div>
                    <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp" class="mt-2 block text-sm">
                    <div wire:loading wire:target="uploads" class="text-xs text-slate-500">Uploading…</div>
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

    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search your products" class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">

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
                        <p class="truncate font-semibold">{{ $p->name }}</p>
                        <p class="text-sm text-slate-600">
                            {{ $currencySymbol }}{{ number_format($p->effective_price, 2) }}
                            @if ($p->discount_percent)<span class="text-xs text-slate-400 line-through">{{ $currencySymbol }}{{ number_format((float) $p->price, 2) }}</span>@endif
                            @if ($p->category) · {{ $p->category->name }}@endif
                        </p>
                        <p class="text-xs">
                            @unless ($p->is_approved)<span class="font-semibold text-amber-700">Awaiting approval</span> @endunless
                            @unless ($p->is_active)<span class="font-semibold text-slate-500">Hidden</span>@endunless
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <label class="flex items-center gap-1 text-xs text-slate-500">Stock
                            <input type="text" inputmode="numeric" value="{{ $p->stock }}" wire:change="setStock({{ $p->id }}, $event.target.value)"
                                   @class(['w-16 rounded border px-2 py-1 text-right text-sm', 'border-red-400 text-red-700' => $p->stock <= 0, 'border-slate-300' => $p->stock > 0])>
                        </label>
                        <div class="flex gap-3 text-xs">
                            <button type="button" wire:click="edit({{ $p->id }})" class="font-medium text-blue-700">Edit</button>
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
