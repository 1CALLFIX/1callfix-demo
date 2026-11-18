<?php
    // Rent anything (owner decision 2026-11-18): four numbered, coloured steps; taps from Rental Settings lists for the
    // picked area; the person types only the name, the price and (optionally) a few words. One Send / Save bar.
    $inp = 'mt-1.5 block min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-base focus:outline focus:outline-2 focus:outline-blue-600';
    $chip = 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border-2 px-4 text-sm font-medium transition';
    $step = fn ($colour) => 'grid h-9 w-9 shrink-0 place-items-center rounded-full text-base font-bold text-white '.$colour;
    $err = fn ($f) => $errors->has($f) ? '<p class="mt-1.5 text-sm text-red-700">'.e($errors->first($f)).'</p>' : '';
?>
<div class="mb-bottom-nav">
<div class="mx-auto max-w-3xl space-y-5 px-4 py-8 pb-40 sm:px-6">
    <a href="{{ route('customer.rent-items') }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">&larr; Your items</a>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $item ? $item->name : 'Rent out something' }}</h1>
        @if ($item)
            <span @class(['rounded-full px-3 py-1 text-sm font-semibold',
                'bg-emerald-100 text-emerald-800' => $item->status === 'approved', 'bg-amber-100 text-amber-800' => $item->status === 'in_review',
                'bg-red-100 text-red-700' => $item->status === 'rejected', 'bg-slate-100 text-slate-700' => $item->status === 'draft']) data-testid="item-status">{{ $item->statusLabel() }}</span>
        @endif
    </div>
    @if (! $item)
        <p class="text-sm text-slate-600">Camera, speaker, party lights, furniture, tools... Post it in 4 quick steps. We check every post, then people near you can call or WhatsApp you. No commission.</p>
    @endif

    @if ($blocked)
        <div role="alert" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="item-blocked">{{ $blocked }}</div>
    @endif
    @if ($zones->isEmpty() && ! $item)
        <div role="status" class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" data-testid="item-no-areas">Posting is not open in your area yet. Please check again soon.</div>
    @endif
    @if ($item?->status === 'rejected' && $item->rejection_reason)
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please change this and send it again</p>
            <p class="mt-1">{{ $item->rejection_reason }}</p>
        </div>
    @elseif ($item?->status === 'in_review')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">⏳ We are checking your item. You can change it again once we reply, usually within a day.</div>
    @elseif ($item?->status === 'approved')
        <div role="status" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">✅ Price, deposit and delivery change at once. A new name, kind, photo, description or area is checked by us first.</div>
    @endif

    @error('item') <div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="item-error">{{ $message }}</div> @enderror
    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="item-notice">{{ $notice }}</div> @endif

    <fieldset @disabled(! $editable || ($blocked && ! $item) || ($zones->isEmpty() && ! $item)) class="space-y-5">
        {{-- 1. Photos --}}
        <section class="space-y-4 rounded-2xl border-2 border-fuchsia-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-fuchsia-600') }}">1</span><h2 class="text-lg font-semibold">Photos</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Main photo</p>
                <?php $coverUrl = \App\Models\RentalItem::imageUrl($item?->cover_image); ?>
                @if ($cover)
                    <img src="{{ $cover->temporaryUrl() }}" alt="New main photo" class="mt-2 aspect-[3/2] w-full max-w-md rounded-2xl object-cover">
                @elseif ($coverUrl)
                    <img src="{{ $coverUrl }}" alt="Main photo" class="mt-2 aspect-[3/2] w-full max-w-md rounded-2xl object-cover">
                @endif
                <label class="mt-2 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-fuchsia-300 bg-fuchsia-50 px-4 text-sm font-semibold text-fuchsia-800">
                    📷 {{ $coverUrl || $cover ? 'Change main photo' : 'Add main photo' }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" wire:model="cover" aria-label="Main photo" class="sr-only">
                </label>
                <x-ui.image-size use="hall_photo" />
                <p wire:loading wire:target="cover" class="mt-1 text-sm text-slate-500">Uploading…</p>
                {!! $err('cover') !!}
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">More photos <span class="font-normal text-slate-500">({{ $minPhotos > 0 ? 'at least '.$minPhotos.', ' : '' }}real photos get more calls)</span></p>
                @if ($photos !== [])
                    <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach ($photos as $i => $p)
                            <li class="relative" wire:key="iphoto-{{ $i }}-{{ md5($p) }}">
                                <img src="{{ \App\Models\RentalItem::imageUrl($p) }}" alt="Photo {{ $i + 1 }}" class="aspect-square w-full rounded-xl object-cover">
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
                @if ($newPhotos !== []) <p class="mt-1 text-sm text-fuchsia-800">{{ count($newPhotos) }} new photo(s) ready. They are added when you save.</p> @endif
                <p wire:loading wire:target="newPhotos" class="mt-1 text-sm text-slate-500">Uploading…</p>
                {!! $err('newPhotos') !!}
                {!! $err('newPhotos.*') !!}
            </div>
        </section>

        {{-- 2. What is it --}}
        <section class="space-y-4 rounded-2xl border-2 border-blue-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-blue-600') }}">2</span><h2 class="text-lg font-semibold">What is it?</h2></div>
            <div>
                <label for="it-name" class="block text-sm font-medium text-slate-900">Name <span class="font-normal text-slate-500">(e.g. Sony camera, JBL party speaker, 6-seater dining table)</span></label>
                <input id="it-name" type="text" wire:model="name" maxlength="80" class="{{ $inp }}" data-testid="item-name">
                {!! $err('name') !!}
            </div>
            <div>
                <p class="text-sm font-medium text-slate-900">Kind</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Kind of item" data-testid="item-categories">
                    @foreach ($categories as $c)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="category" value="{{ $c }}" class="sr-only"> {{ $c }}
                        </label>
                    @endforeach
                </div>
                {!! $err('category') !!}
            </div>
            @if ($conditions !== [])
                <div>
                    <p class="text-sm font-medium text-slate-900">Condition <span class="font-normal text-slate-500">(optional)</span></p>
                    <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Condition">
                        @foreach ($conditions as $c)
                            <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-600 has-[:checked]:text-white">
                                <input type="radio" wire:model="condition" value="{{ $c }}" class="sr-only"> {{ $c }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div>
                <label for="it-about" class="block text-sm font-medium text-slate-900">A few words <span class="font-normal text-slate-500">(optional: what comes with it, rules)</span></label>
                <textarea id="it-about" wire:model="about" rows="3" maxlength="2000" class="{{ $inp }}"></textarea>
                {!! $err('about') !!}
            </div>
        </section>

        {{-- 3. Price --}}
        <section class="space-y-4 rounded-2xl border-2 border-emerald-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-emerald-600') }}">3</span><h2 class="text-lg font-semibold">Price</h2></div>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="it-price" class="block text-sm font-medium text-slate-900">Your price ({{ $symbol }})</label>
                    <input id="it-price" type="number" inputmode="decimal" min="0" wire:model="price" class="{{ $inp }} max-w-[10rem]" data-testid="item-price">
                </div>
                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Price is per">
                    @foreach ($units as $u)
                        <label class="{{ $chip }} border-slate-200 bg-white text-slate-700 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="priceUnit" value="{{ $u }}" class="sr-only"> per {{ mb_strtolower($u) }}
                        </label>
                    @endforeach
                </div>
            </div>
            {!! $err('price') !!}
            {!! $err('priceUnit') !!}
            <div>
                <label for="it-deposit" class="block text-sm font-medium text-slate-900">Deposit ({{ $symbol }}) <span class="font-normal text-slate-500">(optional, returned when the item comes back)</span></label>
                <input id="it-deposit" type="number" inputmode="decimal" min="0" wire:model="deposit" class="{{ $inp }} max-w-[10rem]">
                {!! $err('deposit') !!}
            </div>
            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-medium text-slate-900">
                <input type="checkbox" wire:model="canDeliver" class="h-5 w-5 rounded border-slate-300 text-emerald-600"> 🚚 I can deliver it
            </label>
        </section>

        {{-- 4. Where --}}
        <section class="space-y-4 rounded-2xl border-2 border-amber-100 bg-white p-4 sm:p-6">
            <div class="flex items-center gap-3"><span class="{{ $step('bg-amber-500') }}">4</span><h2 class="text-lg font-semibold">Where is it?</h2></div>
            <div>
                <p class="text-sm font-medium text-slate-900">Your area</p>
                <div class="mt-2 flex flex-wrap gap-2" data-testid="item-zones">
                    @foreach ($zones as $z)
                        <button type="button" wire:click="pickZone({{ $z->id }})" @class(['min-h-11 rounded-full border-2 px-4 text-sm font-semibold', 'border-amber-500 bg-amber-500 text-white' => $zoneId === (string) $z->id, 'border-amber-200 bg-amber-50 text-amber-900' => $zoneId !== (string) $z->id])>📍 {{ $z->name }}</button>
                    @endforeach
                </div>
                {!! $err('zoneId') !!}
            </div>
            <div>
                <label for="it-landmark" class="block text-sm font-medium text-slate-900">Landmark <span class="font-normal text-slate-500">(optional, e.g. near RTC bus stand; never your house number)</span></label>
                <input id="it-landmark" type="text" wire:model="landmark" maxlength="160" class="{{ $inp }}">
                {!! $err('landmark') !!}
            </div>
            <p class="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">🛡️ Your number is shown only to signed-in people who tap "Show owner's number". Meet in a safe place and check the deposit before you hand over.</p>
        </section>
    </fieldset>

    @if ($editable && ! ($blocked && ! $item) && ! ($zones->isEmpty() && ! $item))
        <div class="fixed inset-x-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur" style="bottom: calc(4rem + env(safe-area-inset-bottom, 0px));">
            <div class="mx-auto flex max-w-3xl items-center gap-2">
                @if ($item === null || $item->isEditable())
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="item-send">Send for review ✓</button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="submit,save,cover,newPhotos" class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800" data-testid="item-save">Save</button>
                @else
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,cover,newPhotos" class="min-h-12 flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="item-save">Save changes</button>
                @endif
            </div>
        </div>
    @endif
</div>
</div>
