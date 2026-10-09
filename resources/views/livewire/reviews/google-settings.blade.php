<div>
    <h1 class="text-xl font-semibold mb-1">Google reviews</h1>
    <p class="text-sm text-gray-500 mb-4">
        Shows your real Google Business reviews on the home page to build trust. Nothing is written or edited here: reviews appear exactly as Google
        returns them, and the section stays hidden until there is fresh data. Every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    @php $input = 'w-full border rounded px-3 py-2 text-sm'; @endphp

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-2">Status</h2>
        <ul class="text-sm space-y-1">
            <li>API key on the server: <strong class="{{ $keyConfigured ? 'text-green-700' : 'text-red-700' }}">{{ $keyConfigured ? 'configured' : 'missing — add GOOGLE_PLACES_API_KEY to the server .env' }}</strong></li>
            <li>Last fetched: <strong>{{ $fetchedAt ? $fetchedAt->diffForHumans() : 'never' }}</strong></li>
            <li>On the home page now: <strong>{{ $showing ? count($showing['reviews']).' review(s)' : 'nothing (hidden)' }}</strong></li>
            @if ($lastError !== '')
                <li class="text-red-700">Last error: {{ $lastError }}</li>
            @endif
        </ul>
        <p class="text-xs text-gray-500 mt-2">Google only allows Places content to be kept for a short time, so reviews older than {{ \App\Services\Reviews\GoogleReviews::MAX_AGE_DAYS }} days are hidden automatically if a refresh keeps failing.</p>
    </x-ui.card>

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <label class="flex items-start gap-2 text-sm mb-4">
                <input type="checkbox" wire:model="enabled" class="mt-1 rounded border-gray-300">
                <span><strong>Show Google reviews on the home page</strong></span>
            </label>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" for="g-place">Google Place ID</label>
                    <input id="g-place" type="text" wire:model="placeId" placeholder="ChIJ…" class="{{ $input }} font-mono">
                    <p class="text-xs text-gray-500 mt-1">Your business's Place ID. Open your Google "write a review" link and copy the value after <code>placeid=</code>.</p>
                    @error('placeId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" for="g-write">"Write a review" link (optional)</label>
                    <input id="g-write" type="text" wire:model="reviewUrl" placeholder="https://g.page/r/…/review" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">Adds a "Write a review" button. Blank = no button.</p>
                    @error('reviewUrl') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="g-max">How many reviews to show</label>
                    <select id="g-max" wire:model="max" class="{{ $input }}">
                        @foreach ([1, 2, 3, 4, 5] as $n) <option value="{{ $n }}">{{ $n }}</option> @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Google returns at most 5.</p>
                    @error('max') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="g-min">Only show reviews of at least</label>
                    <select id="g-min" wire:model="minRating" class="{{ $input }}">
                        @foreach ([1, 2, 3, 4, 5] as $n) <option value="{{ $n }}">{{ $n }} star{{ $n > 1 ? 's' : '' }}</option> @endforeach
                    </select>
                    @error('minRating') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="g-hours">Refresh from Google every (hours)</label>
                    <input id="g-hours" type="number" min="1" max="168" wire:model="refreshHours" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">Each refresh is one billable Google Places request.</p>
                    @error('refreshHours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit">Save</x-ui.button>
            <button type="button" wire:click="refreshNow" wire:loading.attr="disabled" class="text-sm underline text-blue-700">Fetch from Google now (uses saved settings)</button>
        </div>
    </form>
</div>
