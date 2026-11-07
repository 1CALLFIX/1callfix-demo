<div>
    <h1 class="text-xl font-semibold mb-1">Website text &amp; language</h1>
    <p class="text-sm text-gray-500 mb-4">
        Every word customers read, in English and each website language. Leave a box blank to use the default shown under it
        (a new language with no default shows the English words). Which languages customers can pick is set in
        <a href="{{ route('admin.languages') }}" class="text-blue-700 underline">Languages</a>. Super Admin only; every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700" data-testid="site-text-flash">{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <div class="grid gap-4 md:grid-cols-[minmax(0,16rem)_1fr]">
                <div>
                    <label class="block text-sm font-medium mb-1" for="st-language">Language next to English</label>
                    <select id="st-language" wire:model.live="language" class="w-full border rounded px-3 py-2 text-sm" data-testid="st-language">
                        @foreach ($languages as $code => $l)
                            <option value="{{ $code }}">{{ $l['english'] }}{{ $l['name'] !== $l['english'] ? ' ('.$l['name'].')' : '' }}{{ $l['on'] ? '' : ' (off)' }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs mt-1 {{ $missing > 0 ? 'text-amber-700' : 'text-green-700' }}" data-testid="st-missing">
                        @if ($missing > 0)
                            {{ $missing }} of {{ $total }} not typed yet (they show in English).
                        @else
                            Every text has {{ $otherEnglish }} words.
                        @endif
                        {{ $otherOn ? 'On for customers.' : 'Off: customers do not see it yet.' }}
                    </p>
                </div>
                <div>
                    <p class="text-sm font-medium mb-1">Spreadsheet (for a translator)</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="download" class="rounded border border-blue-600 px-3 py-1.5 text-sm font-semibold text-blue-700" data-testid="st-download">
                            Download {{ $otherEnglish }} sheet
                        </button>
                        <label class="text-sm">
                            <span class="sr-only">Upload the filled {{ $otherEnglish }} sheet</span>
                            <input type="file" wire:model="sheet" accept=".csv,.xlsx,.xls" class="text-sm" data-testid="st-upload">
                        </label>
                        <span wire:loading wire:target="sheet" class="text-xs text-gray-500">Reading…</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Fill in the 4th column only, keep words like <code>:name</code> as they are, and upload it back here. Uploading fills the boxes below; press Save to make them live.</p>
                    @error('sheet') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    @if ($sheetWarnings)
                        <ul class="mt-2 list-disc pl-5 text-xs text-amber-700" data-testid="st-warnings">
                            @foreach (array_slice($sheetWarnings, 0, 20) as $w)<li>{{ $w }}</li>@endforeach
                            @if (count($sheetWarnings) > 20)<li>…and {{ count($sheetWarnings) - 20 }} more.</li>@endif
                        </ul>
                    @endif
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
                        <div wire:key="st-{{ $row['field'] }}-{{ $language }}">
                            <label class="block text-xs text-gray-500 mb-1" for="st-{{ $row['field'] }}-{{ $language }}">{{ $otherName }}</label>
                            <input id="st-{{ $row['field'] }}-{{ $language }}" type="text" maxlength="500" lang="{{ $language }}" @if ($otherRtl) dir="rtl" @endif wire:model="values.{{ $row['field'] }}.{{ $language }}" placeholder="{{ $row['other'] !== '' ? $row['other'] : $row['en'] }}" class="w-full border rounded px-3 py-2 text-sm">
                            <p class="text-xs text-gray-400 mt-1">{{ $row['other'] !== '' ? 'Default: '.$row['other'] : 'Not typed: shows the English' }}</p>
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
