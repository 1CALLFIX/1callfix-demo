<div>
    <h1 class="text-xl font-semibold mb-1">Website text &amp; language</h1>
    <p class="text-sm text-gray-500 mb-4">
        Every word customers read, in English and Telugu. Leave a box blank to use the default shown under it.
        Super Admin only; every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700" data-testid="site-text-flash">{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Telugu</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-start gap-2 text-sm md:col-span-2">
                    <input type="checkbox" wire:model="teluguEnabled" class="mt-0.5">
                    <span>Offer Telugu on the website (a English / తెలుగు switch in the top bar). Off = English everywhere.
                        Have a native Telugu reader check the Telugu texts below before switching this on.</span>
                </label>
                <div>
                    <label class="block text-sm font-medium mb-1" for="st-default">Language for a first-time visitor</label>
                    <select id="st-default" wire:model="defaultLocale" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="en">English</option>
                        <option value="te">తెలుగు (Telugu)</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">They can switch at any time; the choice is remembered on their device.</p>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @foreach ($groups as $g => $label)
                    <button type="button" wire:click="$set('group', '{{ $g }}')"
                            @class(['rounded-full px-3 py-1 text-sm border', 'bg-blue-600 text-white border-blue-600' => $group === $g && $search === '', 'bg-white text-gray-700' => ! ($group === $g && $search === '')])>
                        {{ $label }} <span class="opacity-70">({{ $counts[$g] ?? 0 }})</span>
                    </button>
                @endforeach
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search all texts…" class="ml-auto border rounded px-3 py-1.5 text-sm w-56">
            </div>

            <div class="divide-y">
                @forelse ($rows as $row)
                    <div class="grid gap-3 py-3 md:grid-cols-[minmax(0,14rem)_1fr_1fr]" wire:key="st-{{ $row['field'] }}">
                        <div>
                            <p class="text-sm font-medium">{{ $row['label'] }}</p>
                            <p class="text-xs text-gray-400 font-mono">{{ $row['key'] }}</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1" for="st-{{ $row['field'] }}-en">English</label>
                            <input id="st-{{ $row['field'] }}-en" type="text" maxlength="500" wire:model="values.{{ $row['field'] }}.en" placeholder="{{ $row['en'] }}" class="w-full border rounded px-3 py-2 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Default: {{ $row['en'] }}</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1" for="st-{{ $row['field'] }}-te">తెలుగు</label>
                            <input id="st-{{ $row['field'] }}-te" type="text" maxlength="500" lang="te" wire:model="values.{{ $row['field'] }}.te" placeholder="{{ $row['te'] }}" class="w-full border rounded px-3 py-2 text-sm">
                            <p class="text-xs text-gray-400 mt-1">Default: {{ $row['te'] }}</p>
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-sm text-gray-500">No text matches.</p>
                @endforelse
            </div>
        </x-ui.card>

        <div class="flex justify-end">
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
        </div>
    </form>
</div>
