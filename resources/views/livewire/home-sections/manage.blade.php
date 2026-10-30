<div>
    <h1 class="text-xl font-semibold mb-1">Homepage sections</h1>
    <p class="text-sm text-gray-500 mb-4">
        What the homepage shows and in what order. The words are on
        <a href="{{ route('admin.site-text') }}" class="text-blue-700 underline">Website text &amp; language</a> (Homepage).
        A door shows only where its business is switched on in Modules. Super Admin only; every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700" data-testid="home-sections-flash">{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">"Explore 1CallFix" doors</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="show" class="mt-0.5"> <span>Show the doors row</span></label>
                <div>
                    <label class="block text-sm font-medium mb-1" for="hs-position">Where</label>
                    <select id="hs-position" wire:model="position" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="top">First, right under the top bar</option>
                        <option value="after_categories">Under the category icons</option>
                    </select>
                </div>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="liveInfo" class="mt-0.5">
                    <span>Live numbers on the doors (services listed, shops open now, parcel price). Real numbers only; a zero shows the plain line.</span></label>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="showPromise" class="mt-0.5"> <span>Show the line under the heading</span></label>
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
