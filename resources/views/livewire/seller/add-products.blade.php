<div class="space-y-4" x-data x-on:seller-scroll-top.window="$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))">
    {{-- Owner 2026-11-03: add many products at once - from the 1CallFix list or from a photo - then check and add. --}}
    <div>
        <a href="{{ route('seller.products') }}" wire:navigate class="text-sm font-medium text-blue-700">← Products</a>
        <h1 class="mt-1 text-2xl font-bold tracking-tight">Add many products</h1>
        <p class="text-sm text-slate-600">Pick from our list or take a photo of your price list. You check the price and stock before anything is added.</p>
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <div class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 text-sm sm:max-w-md" role="tablist">
        <button type="button" role="tab" aria-selected="{{ $tab === 'list' ? 'true' : 'false' }}" wire:click="$set('tab', 'list')" @class(['min-h-11 rounded-lg px-3 font-semibold', 'bg-white shadow-sm text-slate-900' => $tab === 'list', 'text-slate-600' => $tab !== 'list'])>From our list</button>
        <button type="button" role="tab" aria-selected="{{ $tab === 'photo' ? 'true' : 'false' }}" wire:click="$set('tab', 'photo')" @class(['min-h-11 rounded-lg px-3 font-semibold', 'bg-white shadow-sm text-slate-900' => $tab === 'photo', 'text-slate-600' => $tab !== 'photo'])>From a photo</button>
    </div>

    @if ($tab === 'list')
        <x-ui.card>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block text-sm"><span class="font-medium">Category</span>
                    <select wire:model.live="category" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                        <option value="">Choose…</option>
                        @foreach ($categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm"><span class="font-medium">Type</span>
                    <select wire:model.live="subCategory" @disabled($category === '') class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base disabled:bg-slate-50">
                        <option value="">All</option>
                        @foreach ($subCategories as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm"><span class="font-medium">Search</span>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="for example atta, t-shirt" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                </label>
            </div>
            @if ($list->isEmpty())
                <p class="mt-3 text-sm text-slate-500">{{ $category === '' && trim($search) === '' ? 'Choose a category or search to see the items.' : 'No items match.' }}</p>
            @else
                <ul class="mt-3 grid gap-2 sm:grid-cols-2" data-testid="catalog-list">
                    @foreach ($list as $item)
                        @php $on = isset($rows['c'.$item->id]); $owned = $have->has(mb_strtolower($item->name)); @endphp
                        <li wire:key="ci-{{ $item->id }}">
                            <button type="button" wire:click="pickItem({{ $item->id }})" @disabled($owned)
                                    @class(['flex min-h-12 w-full items-center gap-3 rounded-xl border-2 px-3 text-left text-sm transition',
                                        'border-blue-600 bg-blue-50' => $on, 'border-slate-200 hover:border-slate-300' => ! $on && ! $owned, 'border-slate-100 bg-slate-50 text-slate-400' => $owned])>
                                <span @class(['grid h-6 w-6 shrink-0 place-items-center rounded-md border-2 text-xs font-bold', 'border-blue-600 bg-blue-600 text-white' => $on, 'border-slate-300' => ! $on])>{{ $on ? '✓' : '' }}</span>
                                <span class="min-w-0 flex-1"><span class="block truncate font-semibold">{{ $item->name }}</span><span class="block text-xs text-slate-500">{{ $item->sub_category }}</span></span>
                                @if ($owned)<span class="text-xs">In your shop</span>@endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @else
        <x-ui.card>
            @if (! $photoOn)
                <p class="text-sm text-slate-600" data-testid="photo-off">Adding from a photo is not switched on yet. Use "From our list", or add products one by one.</p>
            @elseif ($scan)
                <div wire:poll.2s="checkScan" class="flex items-center gap-3 rounded-xl bg-blue-50 px-4 py-4 text-sm text-blue-900" data-testid="photo-reading">
                    <span class="h-5 w-5 animate-spin rounded-full border-2 border-blue-300 border-t-blue-700" aria-hidden="true"></span>
                    Reading your photo… this takes up to a minute. You can keep working on the list below.
                </div>
            @else
                <p class="text-sm text-slate-700">Take a clear photo of a <strong>price list, bill, shelf or menu board</strong>. Up to {{ $maxPhotos }} photos at a time. We read the product names, prices and sizes; you check them before adding.</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach ($photos as $i => $p)
                        @if (method_exists($p, 'isPreviewable') && $p->isPreviewable())
                            <img src="{{ $p->temporaryUrl() }}" alt="" class="h-24 w-24 rounded-xl border object-cover" wire:key="sp-{{ $i }}">
                        @endif
                    @endforeach
                    @if (count($photos) < $maxPhotos)
                        <label class="flex h-24 w-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-blue-300 bg-blue-50/50 text-blue-700 hover:border-blue-500" data-testid="take-photo">
                            <x-icon name="photo" class="h-7 w-7" />
                            <span class="text-xs font-bold">Take photo</span>
                            <input type="file" wire:model="photos" multiple accept="image/*" capture="environment" class="sr-only">
                        </label>
                    @endif
                </div>
                <div wire:loading wire:target="photos" class="mt-2 text-xs font-medium text-blue-700">Uploading…</div>
                @error('photos') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                @error('photos.*') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                @if ($photos)
                    <x-ui.button type="button" wire:click="readPhotos" wire:loading.attr="disabled" class="mt-3">Read the products</x-ui.button>
                @endif
            @endif
        </x-ui.card>
    @endif

    {{-- The list to check, from either way. --}}
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">Check and add ({{ count($rows) }})</h2>
            @if ($rows)<button type="button" wire:click="clearRows" class="text-sm text-slate-500 underline">Clear the list</button>@endif
        </div>
        @if ($rows === [])
            <p class="mt-2 text-sm text-slate-500">Items you pick or read from a photo show here. Fill in the price and how many you have, then add them all at once.</p>
        @else
            <div class="mt-3 space-y-2" data-testid="rows">
                @foreach ($rows as $key => $row)
                    <div wire:key="row-{{ $key }}" class="grid gap-2 rounded-xl border border-slate-200 p-2 sm:grid-cols-[2fr_1.3fr_0.8fr_0.7fr_1fr_auto] sm:items-start">
                        <div>
                            <input type="text" wire:model="rows.{{ $key }}.name" maxlength="150" aria-label="Name" class="block min-h-10 w-full rounded-lg border border-slate-300 px-2 text-sm">
                            @error("rows.$key.name") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                            @if ($row['from'] === 'photo')<span class="text-[11px] text-slate-400">from photo</span>@endif
                        </div>
                        <select wire:model="rows.{{ $key }}.category_id" aria-label="Category" class="block min-h-10 w-full rounded-lg border border-slate-300 px-2 text-sm">
                            <option value="">Category…</option>
                            @foreach ($shopCategories as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                        <div>
                            <input type="text" inputmode="decimal" wire:model="rows.{{ $key }}.price" placeholder="Price ₹" aria-label="Price" class="block min-h-10 w-full rounded-lg border border-slate-300 px-2 text-sm">
                            @error("rows.$key.price") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <input type="text" inputmode="numeric" wire:model="rows.{{ $key }}.stock" placeholder="Stock" aria-label="Stock" class="block min-h-10 w-full rounded-lg border border-slate-300 px-2 text-sm">
                            @error("rows.$key.stock") <span class="text-xs text-red-700">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex gap-1">
                            <input type="text" inputmode="decimal" wire:model="rows.{{ $key }}.unit_value" placeholder="Size" aria-label="Size, for example 500" class="block min-h-10 w-16 rounded-lg border border-slate-300 px-2 text-sm">
                            <select wire:model="rows.{{ $key }}.unit" aria-label="Unit" class="block min-h-10 min-w-0 flex-1 rounded-lg border border-slate-300 px-1 text-sm">
                                <option value="">—</option>
                                @foreach ($units as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                            </select>
                        </div>
                        <button type="button" wire:click="removeRow('{{ $key }}')" class="min-h-10 rounded-lg px-2 text-sm text-red-700 hover:bg-red-50" aria-label="Take {{ $row['name'] }} off the list">×</button>
                        @if ($errors->has("rows.$key.unit_value") || $errors->has("rows.$key.unit"))<p class="text-xs text-red-700 sm:col-span-6">{{ $errors->first("rows.$key.unit_value") ?: $errors->first("rows.$key.unit") }}</p>@endif
                    </div>
                @endforeach
            </div>
            <x-ui.button type="button" wire:click="addAll" wire:loading.attr="disabled" class="mt-3" data-testid="add-all">Add {{ count($rows) }} products</x-ui.button>
        @endif
    </x-ui.card>
</div>
