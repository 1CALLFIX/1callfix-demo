<div>
    <h1 class="text-xl font-semibold mb-1">Languages</h1>
    <p class="text-sm text-gray-500 mb-4">
        The languages customers can pick on the website. English is always on. To add one: add it below (it starts off),
        type its words in <a href="{{ route('admin.site-text') }}" class="text-blue-700 underline">Website text</a>
        (or download the spreadsheet there, fill it in and upload it), then switch it on here. Any word not typed yet shows in English.
        Staff, partner and rider screens stay in English. Super Admin only; every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700" data-testid="languages-flash">{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500">
                            <th class="py-2 pr-3">On</th>
                            <th class="py-2 pr-3">Language</th>
                            <th class="py-2 pr-3">Name customers see</th>
                            <th class="py-2 pr-3">Code</th>
                            <th class="py-2 pr-3">Right to left</th>
                            <th class="py-2 pr-3">Words typed</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($all as $code => $l)
                            <tr wire:key="lang-{{ $code }}" data-testid="language-row-{{ $code }}">
                                <td class="py-2 pr-3">
                                    <input type="checkbox" wire:model="rows.{{ $code }}.on" @disabled($code === 'en') aria-label="{{ $l['english'] }} on">
                                </td>
                                <td class="py-2 pr-3">
                                    @if ($l['built_in'])
                                        <span class="font-medium">{{ $l['english'] }}</span>
                                    @else
                                        <input type="text" maxlength="40" wire:model="rows.{{ $code }}.english" class="w-36 border rounded px-2 py-1" aria-label="English name of {{ $code }}">
                                        @error('rows.'.$code.'.english') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    @endif
                                </td>
                                <td class="py-2 pr-3">
                                    @if ($l['built_in'])
                                        <span lang="{{ $code }}">{{ $l['name'] }}</span>
                                    @else
                                        <input type="text" maxlength="40" lang="{{ $code }}" @if ($l['rtl']) dir="rtl" @endif wire:model="rows.{{ $code }}.name" class="w-36 border rounded px-2 py-1" aria-label="Name of {{ $code }} in its own script">
                                        @error('rows.'.$code.'.name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                                    @endif
                                </td>
                                <td class="py-2 pr-3 font-mono text-gray-500">{{ $code }}</td>
                                <td class="py-2 pr-3">
                                    @if ($l['built_in'])
                                        <span class="text-gray-400">No</span>
                                    @else
                                        <input type="checkbox" wire:model="rows.{{ $code }}.rtl" aria-label="{{ $l['english'] }} is written right to left">
                                    @endif
                                </td>
                                <td class="py-2 pr-3">
                                    @php $n = min($typed[$code] ?? 0, $total); @endphp
                                    <span @class(['text-green-700' => $n >= $total, 'text-amber-700' => $n < $total])>{{ $n }} of {{ $total }}</span>
                                    @if ($code !== 'en')
                                        <a href="{{ route('admin.site-text', ['language' => $code]) }}" class="ml-2 text-blue-700 underline">Edit words</a>
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    @if (! $l['built_in'] && ! $l['on'])
                                        <button type="button" wire:click="remove('{{ $code }}')" wire:confirm="Remove {{ $l['english'] }}? Its typed words are kept in case you add it again."
                                                class="text-sm text-red-700 underline" data-testid="remove-{{ $code }}">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 max-w-sm">
                <label class="block text-sm font-medium mb-1" for="lang-default">Language for a first-time visitor</label>
                <select id="lang-default" wire:model="defaultLocale" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach ($all as $code => $l)
                        <option value="{{ $code }}">{{ $l['english'] }}{{ $l['name'] !== $l['english'] ? ' ('.$l['name'].')' : '' }}</option>
                    @endforeach
                </select>
                @error('defaultLocale') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-500 mt-1">They can switch at any time from the top bar; the choice is remembered on their device.</p>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
            </div>
        </x-ui.card>
    </form>

    <form wire:submit="add" class="mt-6">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Add a language</h2>
            <div class="grid gap-4 md:grid-cols-4">
                <label class="block text-sm"><span class="font-medium">Language (in English)</span>
                    <input type="text" maxlength="40" wire:model="newEnglish" placeholder="e.g. Tamil" class="mt-1 w-full border rounded px-3 py-2 text-sm">
                    @error('newEnglish') <span class="block text-xs text-red-600 mt-1">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="font-medium">Name in its own script</span>
                    <input type="text" maxlength="40" wire:model="newName" placeholder="e.g. தமிழ்" class="mt-1 w-full border rounded px-3 py-2 text-sm">
                    @error('newName') <span class="block text-xs text-red-600 mt-1">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm"><span class="font-medium">Code (2 or 3 letters)</span>
                    <input type="text" maxlength="3" wire:model="newCode" placeholder="e.g. ta" class="mt-1 w-full border rounded px-3 py-2 text-sm font-mono">
                    @error('newCode') <span class="block text-xs text-red-600 mt-1">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-end gap-2 text-sm pb-2">
                    <input type="checkbox" wire:model="newRtl"> <span>Written right to left (Urdu, Arabic)</span>
                </label>
            </div>
            <p class="text-xs text-gray-500 mt-2">Common codes: hi Hindi, ta Tamil, kn Kannada, ml Malayalam, mr Marathi, bn Bengali, ur Urdu, ar Arabic.</p>
            <div class="mt-4 flex justify-end">
                <button type="submit" class="rounded border border-blue-600 px-4 py-2 text-sm font-semibold text-blue-700">Add language</button>
            </div>
        </x-ui.card>
    </form>
</div>
