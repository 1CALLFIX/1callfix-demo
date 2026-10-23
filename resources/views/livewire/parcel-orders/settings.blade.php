<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold">Parcel Settings</h1>
            <p class="text-sm text-gray-500">Price, limits and rider dispatch for parcel delivery. Switch parcel on or off per location on the <a href="{{ route('admin.modules.index') }}" class="underline">Modules</a> screen.</p>
        </div>
        <a href="{{ route('admin.parcel-orders.index') }}" class="text-sm underline">Parcel Orders →</a>
    </div>

    @if ($flashMessage)
        <div @class(['mb-4 rounded px-4 py-2 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <div class="flex flex-wrap gap-3 text-sm">
                    <label class="block">
                        <span class="block text-xs font-medium mb-1">Applies to</span>
                        <select wire:model.live="scopeType" class="border rounded px-2 py-1.5">
                            <option value="global">Global</option>
                            <option value="franchise">Franchise</option>
                            <option value="zone">Zone</option>
                        </select>
                    </label>
                    @if ($scopeType !== 'global')
                        <label class="block">
                            <span class="block text-xs font-medium mb-1">Franchise</span>
                            <select wire:model.live="scopeFranchiseId" class="border rounded px-2 py-1.5">
                                <option value="">—</option>
                                @foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                    @if ($scopeType === 'zone')
                        <label class="block">
                            <span class="block text-xs font-medium mb-1">Zone</span>
                            <select wire:model.live="scopeZoneId" class="border rounded px-2 py-1.5">
                                <option value="">—</option>
                                @foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                            </select>
                        </label>
                    @endif
                </div>
                <p class="mt-2 text-xs text-gray-500">A value set at a franchise / zone overrides the global one there; blank inherits. An order keeps the price it was booked at.</p>
            </x-ui.card>

            <form wire:submit="save" class="space-y-4">
                @foreach ($groups as $group => $items)
                    <x-ui.card>
                        <h2 class="mb-3 text-sm font-semibold">{{ $group }}</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($items as $meta)
                                <label class="block text-sm">
                                    <span class="block text-xs font-medium mb-1">
                                        {{ $meta['label'] }} @if ($meta['unit'])<span class="text-gray-400">({{ $meta['unit'] }})</span>@endif
                                        @if ($meta['required'] ?? false)<span class="text-red-600">*</span>@endif
                                    </span>
                                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="inputs.{{ $meta['field'] }}"
                                           placeholder="{{ $meta['default'] === null ? 'Not configured' : 'Default '.$meta['default'] }}"
                                           class="w-full border rounded px-2 py-2">
                                    <span class="block font-mono text-[11px] text-gray-400">{{ $meta['key'] }}</span>
                                    <span class="block text-[11px] text-gray-500">{{ $meta['help'] }}</span>
                                    @error('inputs.'.$meta['field']) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </label>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach

                <div class="sticky bottom-0 bg-white/90 py-3"><x-ui.button type="submit">Save parcel settings</x-ui.button></div>
            </form>
        </div>

        <div>
            <x-ui.card class="lg:sticky lg:top-4">
                <h2 class="mb-1 text-sm font-semibold">Price check</h2>
                <p class="mb-3 text-xs text-gray-500">From the values saved for this scope (save first to see a change).</p>
                <div class="grid grid-cols-3 gap-2 text-sm">
                    <label class="block"><span class="block text-xs mb-1">km</span><input type="text" inputmode="decimal" wire:model.live.debounce.300ms="previewKm" class="w-full border rounded px-2 py-1.5"></label>
                    <label class="block"><span class="block text-xs mb-1">kg</span><input type="text" inputmode="decimal" wire:model.live.debounce.300ms="previewKg" class="w-full border rounded px-2 py-1.5"></label>
                    <label class="block"><span class="block text-xs mb-1">Size</span>
                        <select wire:model.live="previewSize" class="w-full border rounded px-2 py-1.5">
                            @foreach ($sizes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                </div>
                @if ($preview['ok'])
                    <p class="mt-3 text-2xl font-bold" data-testid="parcel-price-preview">{{ $currencySymbol }}{{ number_format($preview['total'], 2) }}</p>
                @else
                    <p class="mt-3 text-sm text-amber-700">{{ $preview['message'] }}</p>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
