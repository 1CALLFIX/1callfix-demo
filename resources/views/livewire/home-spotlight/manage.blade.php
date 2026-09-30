<div>
    <h1 class="text-2xl font-bold mb-1">Home Spotlight</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-2xl">
        The numbered tiles next to the categories on the customer home page. Pick a service or a whole category for each
        box, add an optional badge such as “New”, and save. Slots are shown in number order (1 first). Leave a slot empty
        to skip it. With fewer than four slots filled, the remaining tiles are topped up automatically with the most-booked
        services; with nothing set at all, the home page uses the automatic collage.
    </p>

    @if ($notice)
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800" role="status">{{ $notice }}</div>
    @endif

    <form wire:submit="save" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($spots as $i => $slot)
            <x-ui.card>
                <div class="flex items-center justify-between mb-3">
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold text-white">{{ $i }}</span>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="spots.{{ $i }}.active" class="rounded border-gray-300">
                        Show
                    </label>
                </div>

                <label class="block text-xs font-medium text-gray-600 mb-1" for="type-{{ $i }}">Links to</label>
                <select id="type-{{ $i }}" wire:model.live="spots.{{ $i }}.type" class="mb-3 w-full rounded-md border-gray-300 text-sm">
                    <option value="service">A service</option>
                    <option value="category">A whole category</option>
                </select>

                <label class="block text-xs font-medium text-gray-600 mb-1" for="target-{{ $i }}">
                    {{ $slot['type'] === 'category' ? 'Category' : 'Service' }}
                </label>
                <select id="target-{{ $i }}" wire:model="spots.{{ $i }}.target" class="mb-3 w-full rounded-md border-gray-300 text-sm">
                    <option value="">— empty —</option>
                    @if ($slot['type'] === 'category')
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    @else
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                        @endforeach
                    @endif
                </select>

                <label class="block text-xs font-medium text-gray-600 mb-1" for="badge-{{ $i }}">Badge (optional)</label>
                <input id="badge-{{ $i }}" type="text" maxlength="24" wire:model="spots.{{ $i }}.badge" placeholder="New, Featured, Offer…"
                       class="mb-1 w-full rounded-md border-gray-300 text-sm">
                @error("spots.$i.badge") <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                <button type="button" wire:click="clearSlot({{ $i }})" class="mt-2 text-xs text-gray-500 underline hover:text-gray-800">Clear this slot</button>
            </x-ui.card>
        @endforeach

        <div class="md:col-span-2 xl:col-span-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save spotlight</button>
        </div>
    </form>
</div>
