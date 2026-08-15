@props([
    'label',
    'fileModel',
    'validateMethod',
    'commitMethod',
    'cancelMethod',
    'templateMethod',
    'deactivateMissingModel',
    'errors' => [],
    'rows' => null,
    'message' => null,
    'run' => null,
])

{{-- Reused by Categories/Subcategories/Services import panels — all wire:
     directives here target whichever Livewire component includes this,
     this is a plain Blade partial, not its own component. Pipeline (mission
     Phase 14 — Master Catalog Import): upload -> validate/relationship/
     duplicate/price/image checks (server-side, in CatalogImporter) ->
     this preview -> confirm -> transaction-safe commit -> the report below. --}}
<div class="bg-white rounded-lg shadow-sm p-4 mb-4 border border-indigo-100">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold">Import {{ $label }}</h3>
        <button type="button" wire:click="{{ $cancelMethod }}" class="text-xs text-gray-400 hover:text-gray-600">Close ✕</button>
    </div>

    @if ($message && $run)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-3 text-sm">{{ $message }}</div>

        {{-- IMPORT REPORT — a real, persisted CatalogImportRun, not just this ephemeral message. --}}
        <div class="grid grid-cols-3 md:grid-cols-6 gap-2 mb-3 text-center text-xs">
            <div class="bg-emerald-50 text-emerald-700 rounded p-2"><div class="font-bold text-base">{{ $run->created_count }}</div>Created</div>
            <div class="bg-blue-50 text-blue-700 rounded p-2"><div class="font-bold text-base">{{ $run->updated_count }}</div>Updated</div>
            <div class="bg-gray-50 text-gray-600 rounded p-2"><div class="font-bold text-base">{{ $run->unchanged_count }}</div>Unchanged</div>
            <div class="bg-amber-50 text-amber-700 rounded p-2"><div class="font-bold text-base">{{ $run->deactivated_count }}</div>Deactivated</div>
            <div class="bg-gray-50 text-gray-600 rounded p-2"><div class="font-bold text-base">{{ $run->skipped_count }}</div>Skipped</div>
            <div class="bg-red-50 text-red-700 rounded p-2"><div class="font-bold text-base">{{ $run->failed_count }}</div>Failed</div>
        </div>
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
        <p class="text-xs text-gray-400 mt-2">.xlsx, .xls, or .csv. A blank <span class="font-mono">id</span>/<span class="font-mono">external_id</span> creates a new row; a populated one matches an existing row by that source identity (never by forcing it onto our own id) — see the preview below for exactly what each row will do.</p>
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
        @php
            $counts = collect($rows)->countBy('outcome');
            $duplicateCount = collect($rows)->where('possible_duplicate', true)->count();
        @endphp
        <div class="mt-3">
            <p class="text-sm text-green-700 mb-2">
                {{ count($rows) }} row(s) validated —
                {{ $counts->get('created', 0) }} to create,
                {{ $counts->get('updated', 0) }} to update,
                {{ $counts->get('unchanged', 0) }} unchanged
                @if ($counts->get('skipped', 0)) , {{ $counts->get('skipped', 0) }} blank row(s) skipped @endif.
            </p>
            @if ($duplicateCount)
                <p class="text-xs text-amber-700 bg-amber-50 rounded p-2 mb-2">{{ $duplicateCount }} row(s) look like a possible duplicate of an existing record (same name in the same category) — review the preview table before confirming.</p>
            @endif

            <div class="border rounded overflow-hidden max-h-64 overflow-y-auto mb-3">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-left sticky top-0">
                        <tr>
                            <th class="px-2 py-1">Row</th>
                            <th class="px-2 py-1">Name</th>
                            <th class="px-2 py-1">External ID</th>
                            <th class="px-2 py-1">Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr class="border-t @if($r['possible_duplicate']) bg-amber-50 @endif">
                                <td class="px-2 py-1">{{ $r['row'] }}</td>
                                <td class="px-2 py-1">{{ $r['name'] ?? '—' }}</td>
                                <td class="px-2 py-1 font-mono">{{ $r['external_id'] ?? '—' }}</td>
                                <td class="px-2 py-1">
                                    <span @class([
                                        'px-1.5 py-0.5 rounded',
                                        'bg-emerald-100 text-emerald-700' => $r['outcome'] === 'created',
                                        'bg-blue-100 text-blue-700' => $r['outcome'] === 'updated',
                                        'bg-gray-100 text-gray-600' => in_array($r['outcome'], ['unchanged', 'skipped']),
                                    ])>{{ $r['outcome'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($deactivateMissingModel)
                <label class="flex items-center gap-2 text-xs text-gray-600 mb-3">
                    <input type="checkbox" wire:model="{{ $deactivateMissingModel }}" class="rounded">
                    Deactivate existing records (previously imported, not manually created) whose external ID isn't in this file
                </label>
            @endif

            <button type="button" wire:click="{{ $commitMethod }}" wire:loading.attr="disabled" wire:target="{{ $commitMethod }}"
                    class="px-4 py-1.5 bg-emerald-600 text-white rounded text-xs font-medium hover:bg-emerald-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="{{ $commitMethod }}">Commit Import</span>
                <span wire:loading wire:target="{{ $commitMethod }}">Importing…</span>
            </button>
        </div>
    @endif
</div>
