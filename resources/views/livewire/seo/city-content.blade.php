<div>
    <h1 class="text-2xl font-bold mb-1">City page content</h1>
    <p class="text-sm text-gray-500 mb-4">The title, meta description and intro text shown on a city page. Web addresses (slugs) are not edited here.</p>

    @if ($flashMessage)
        <div class="rounded p-3 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    <x-ui.card class="mb-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-sm font-medium mb-1">City</label>
                <select wire:model.live="cityId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Choose a city…</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Page</label>
                <select wire:model.live="subjectType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="city">City page</option>
                    <option value="category">A category page</option>
                    <option value="service">A service page</option>
                </select>
            </div>
            @if ($subjectType !== 'city')
                <div>
                    <label class="block text-sm font-medium mb-1">{{ $subjectType === 'category' ? 'Category' : 'Service' }}</label>
                    <select wire:model.live="subjectId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Choose…</option>
                        @foreach ($subjects as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </x-ui.card>

    @if ($cityId && ($subjectType === 'city' || $subjectId))
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Page title</label>
                    <input type="text" wire:model.live.debounce.300ms="title" maxlength="160" class="w-full border rounded px-3 py-2 text-sm">
                    @error('title') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Meta description</label>
                    <textarea wire:model.live.debounce.300ms="metaDescription" rows="2" maxlength="320" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    @error('metaDescription') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <x-seo.preview :title="\App\Services\Seo\SeoSettings::renderTitle($title)" :description="$metaDescription" :url="\App\Support\Seo::canonicalBase()" />
                <div>
                    <label class="block text-sm font-medium mb-1">Intro text</label>
                    <textarea wire:model="intro" rows="5" maxlength="4000" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    @error('intro') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <x-ui.button wire:click="save">Save</x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
