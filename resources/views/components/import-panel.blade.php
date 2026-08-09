@props([
    'label',
    'fileModel',
    'validateMethod',
    'commitMethod',
    'cancelMethod',
    'templateMethod',
    'errors' => [],
    'rows' => null,
    'message' => null,
])

{{-- Reused by Categories/Subcategories/Services import panels — all wire:
     directives here target whichever Livewire component includes this
     (Categories\Index or Services\Index), this is a plain Blade partial,
     not its own component. --}}
<div class="bg-white rounded-lg shadow-sm p-4 mb-4 border border-indigo-100">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold">Import {{ $label }}</h3>
        <button type="button" wire:click="{{ $cancelMethod }}" class="text-xs text-gray-400 hover:text-gray-600">Close ✕</button>
    </div>

    @if ($message)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-3 text-sm">{{ $message }}</div>
    @endif

    @if (empty($errors) && $rows === null)
        <div class="flex items-center gap-3 flex-wrap">
            <button type="button" wire:click="{{ $templateMethod }}" class="px-3 py-1.5 border border-gray-300 rounded text-xs hover:bg-gray-50">
                Download template
            </button>
            <input type="file" wire:model="{{ $fileModel }}" accept=".xlsx,.xls,.csv" class="text-xs">
            <button type="button" wire:click="{{ $validateMethod }}" wire:loading.attr="disabled" wire:target="{{ $validateMethod }},{{ $fileModel }}"
                    class="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="{{ $validateMethod }}">Validate</span>
                <span wire:loading wire:target="{{ $validateMethod }}">Validating…</span>
            </button>
            @error($fileModel) <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
        </div>
        <p class="text-xs text-gray-400 mt-2">.xlsx, .xls, or .csv. Rows with a blank <span class="font-mono">id</span> column are created new; a populated <span class="font-mono">id</span> updates that existing row (or creates it with that exact id, so a Glover-format sheet's cross-references still line up).</p>
    @endif

    @if (! empty($errors))
        <div class="mt-3">
            <p class="text-xs text-red-600 font-medium mb-2">{{ count($errors) }} problem(s) found — nothing was imported. Fix these in the sheet and re-upload.</p>
            <div class="border rounded overflow-hidden max-h-64 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="bg-red-50 text-left sticky top-0">
                        <tr>
                            <th class="px-2 py-1">Row</th>
                            <th class="px-2 py-1">Field</th>
                            <th class="px-2 py-1">Problem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($errors as $err)
                            <tr class="border-t">
                                <td class="px-2 py-1">{{ $err['row'] }}</td>
                                <td class="px-2 py-1 font-mono">{{ $err['field'] }}</td>
                                <td class="px-2 py-1">{{ $err['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" wire:click="{{ $validateMethod }}" class="mt-2 text-xs text-blue-600 hover:underline">Re-check this file</button>
        </div>
    @endif

    @if ($rows !== null)
        <div class="mt-3 flex items-center gap-3">
            <p class="text-sm text-green-700">{{ count($rows) }} row(s) validated, ready to import.</p>
            <button type="button" wire:click="{{ $commitMethod }}" wire:loading.attr="disabled" wire:target="{{ $commitMethod }}"
                    class="px-4 py-1.5 bg-emerald-600 text-white rounded text-xs font-medium hover:bg-emerald-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="{{ $commitMethod }}">Commit Import</span>
                <span wire:loading wire:target="{{ $commitMethod }}">Importing…</span>
            </button>
        </div>
    @endif
</div>
