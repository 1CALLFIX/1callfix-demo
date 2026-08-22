@props([
    'label',
    'fileModel',
    'validateMethod',
    'commitMethod',
    'cancelMethod',
    'warning',
    'errors' => [],
    'rows' => null,
    'message' => null,
    'run' => null,
])

{{--
    Export Everywhere + Import Where It's Safe session, Part 3 — Bulk
    Pre-Register. Deliberately its OWN component, not a reuse of
    x-import-panel: the prompt is explicit that this must read as clearly
    distinct from "import" in the UI, not merely in an internal method
    name, so nobody mistakes it for activating fully-usable accounts.
    Same underlying discipline as x-import-panel (upload -> validate ->
    preview -> confirm -> transaction-safe commit -> report), deliberately
    simpler shape (no external_id/deactivate-missing concepts — those
    don't apply to account shells matched by phone number).
--}}
<div class="bg-white rounded-lg shadow-sm p-4 mb-4 border border-amber-200">
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-semibold">Bulk Pre-Register {{ $label }}</h3>
        <button type="button" wire:click="{{ $cancelMethod }}" class="text-xs text-gray-400 hover:text-gray-600">Close ✕</button>
    </div>

    <p class="text-xs text-amber-800 bg-amber-50 rounded p-2 mb-3">{{ $warning }}</p>

    @if ($message && $run)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-3 text-sm">{{ $message }}</div>

        <div class="grid grid-cols-2 gap-2 mb-3 text-center text-xs">
            <div class="bg-emerald-50 text-emerald-700 rounded p-2"><div class="font-bold text-base">{{ $run->created_count }}</div>Created</div>
            <div class="bg-gray-50 text-gray-600 rounded p-2"><div class="font-bold text-base">{{ $run->skipped_count }}</div>Skipped (already registered)</div>
        </div>
    @endif

    @if (empty($errors) && $rows === null)
        <div class="flex items-center gap-3 flex-wrap">
            <input type="file" wire:model="{{ $fileModel }}" accept=".xlsx,.xls,.csv" class="text-xs">
            <button type="button" wire:click="{{ $validateMethod }}" wire:loading.attr="disabled" wire:target="{{ $validateMethod }},{{ $fileModel }}"
                    class="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="{{ $validateMethod }}">Validate</span>
                <span wire:loading wire:target="{{ $validateMethod }}">Validating…</span>
            </button>
            @error($fileModel) <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
        </div>
    @endif

    @if (! empty($errors))
        <div class="mt-3">
            <p class="text-xs text-red-600 font-medium mb-2">{{ count($errors) }} problem(s) found — those rows will NOT be created.</p>
            <div class="border rounded overflow-hidden max-h-64 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="bg-red-50 text-left sticky top-0">
                        <tr><th class="px-2 py-1">Row</th><th class="px-2 py-1">Field</th><th class="px-2 py-1">Problem</th></tr>
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
        </div>
    @endif

    @if ($rows !== null)
        @php $counts = collect($rows)->countBy('outcome'); @endphp
        <div class="mt-3">
            <p class="text-sm text-green-700 mb-2">
                {{ count($rows) }} row(s) validated —
                {{ $counts->get('created', 0) }} to create,
                {{ $counts->get('skipped_existing', 0) }} already registered (skipped)
                @if ($counts->get('skipped_blank', 0)) , {{ $counts->get('skipped_blank', 0) }} blank row(s) skipped @endif.
            </p>

            <div class="border rounded overflow-hidden max-h-64 overflow-y-auto mb-3">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-left sticky top-0">
                        <tr><th class="px-2 py-1">Row</th><th class="px-2 py-1">Name</th><th class="px-2 py-1">Phone</th><th class="px-2 py-1">Outcome</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr class="border-t">
                                <td class="px-2 py-1">{{ $r['row'] }}</td>
                                <td class="px-2 py-1">{{ $r['name'] ?? '—' }}</td>
                                <td class="px-2 py-1 font-mono">{{ $r['phone'] ?? '—' }}</td>
                                <td class="px-2 py-1">
                                    <span @class([
                                        'px-1.5 py-0.5 rounded',
                                        'bg-emerald-100 text-emerald-700' => $r['outcome'] === 'created',
                                        'bg-gray-100 text-gray-600' => $r['outcome'] !== 'created',
                                    ])>{{ str_replace('_', ' ', $r['outcome']) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="button" wire:click="{{ $commitMethod }}" wire:loading.attr="disabled" wire:target="{{ $commitMethod }}"
                    class="px-4 py-1.5 bg-emerald-600 text-white rounded text-xs font-medium hover:bg-emerald-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="{{ $commitMethod }}">Confirm Pre-Register</span>
                <span wire:loading wire:target="{{ $commitMethod }}">Creating…</span>
            </button>
        </div>
    @endif
</div>
