<div>
    <h1 class="text-2xl font-bold mb-1">Search Box</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-2xl">
        Controls the website's header search box: the services shown one after another in its placeholder text, how big the text is,
        its colour and how fast it rotates. Leave every service slot empty to keep the automatic choice (your most-booked services).
    </p>

    @if ($notice)
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800" role="status">{{ $notice }}</div>
    @endif

    {{-- Live preview --}}
    <x-ui.card class="mb-4">
        <p class="text-xs font-medium text-gray-500 mb-2">Preview</p>
        <div class="flex max-w-sm items-center rounded-full border border-gray-200 bg-white px-4 py-2 shadow-sm">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="mr-2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/></svg>
            <span style="{{ $color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? 'color:'.$color : 'color:#94a3b8' }};font-size:{{ ['small' => '12px', 'medium' => '14px', 'large' => '16px'][$size] ?? '14px' }}">{{ trim($prefix) }} '{{ $previewService }}'</span>
        </div>
    </x-ui.card>

    <form wire:submit="save" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-ui.card class="lg:col-span-1">
            <h2 class="font-semibold mb-3">Look</h2>

            <label class="block text-xs font-medium text-gray-600 mb-1">Text size</label>
            <div class="mb-3 flex gap-2">
                @foreach ($sizes as $key => [$label])
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded border px-3 py-1.5 text-sm {{ $size === $key ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-gray-300' }}">
                        <input type="radio" wire:model.live="size" value="{{ $key }}" class="sr-only"> {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('size') <p class="text-xs text-red-600 mb-2">{{ $message }}</p> @enderror

            <label class="block text-xs font-medium text-gray-600 mb-1" for="sb-color">Text colour</label>
            <div class="mb-1 flex items-center gap-2">
                <input type="color" value="{{ $color !== '' ? $color : '#94a3b8' }}" wire:change="$set('color', $event.target.value)" class="h-9 w-12 cursor-pointer rounded border border-gray-300 p-0.5" aria-label="Pick a colour">
                <input id="sb-color" type="text" wire:model.live.debounce.300ms="color" placeholder="#94a3b8" maxlength="7" class="w-28 rounded-md border-gray-300 text-sm">
                <button type="button" wire:click="resetColor" class="text-xs text-gray-500 underline hover:text-gray-800">Reset</button>
            </div>
            @error('color') <p class="text-xs text-red-600 mb-2">{{ $message }}</p> @enderror
            <p class="mb-3 text-xs text-gray-400">Empty = the default soft grey.</p>

            <label class="block text-xs font-medium text-gray-600 mb-1" for="sb-prefix">Lead-in words</label>
            <input id="sb-prefix" type="text" wire:model.live.debounce.300ms="prefix" maxlength="40" class="mb-1 w-full rounded-md border-gray-300 text-sm">
            @error('prefix') <p class="text-xs text-red-600 mb-2">{{ $message }}</p> @enderror

            <label class="mt-3 block text-xs font-medium text-gray-600 mb-1" for="sb-seconds">Seconds per service (2–10)</label>
            <input id="sb-seconds" type="number" min="2" max="10" wire:model.live="seconds" class="w-24 rounded-md border-gray-300 text-sm">
            @error('seconds') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </x-ui.card>

        <x-ui.card class="lg:col-span-2">
            <h2 class="font-semibold mb-1">Services to rotate</h2>
            <p class="mb-3 text-xs text-gray-500">They show in this order, top to bottom. Only active services are used.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach ($picks as $i => $pick)
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">{{ $i }}</span>
                        <select wire:model.live="picks.{{ $i }}" class="w-full rounded-md border-gray-300 text-sm" aria-label="Service {{ $i }}">
                            <option value="">— empty —</option>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}">{{ $service->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="clearPick({{ $i }})" class="text-xs text-gray-400 hover:text-gray-700" aria-label="Clear slot {{ $i }}">✕</button>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <div class="lg:col-span-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save search box</button>
        </div>
    </form>
</div>
