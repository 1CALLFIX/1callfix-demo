@props(['bars'])
{{-- REF 1CF-ADMIN-ROWACTIONS-001 — result message + the two confirmation bars for
     App\Livewire\Concerns\HasRowArchive. Pass :bars="$archiveBars" from the view. --}}
@if ($bars['flash'] !== '')
    <div class="mb-3 rounded-md border px-4 py-2 text-sm {{ $bars['flashType'] === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800' }}" role="status">
        {{ $bars['flash'] }}
    </div>
@endif

@if ($bars['archive'])
    <div class="mb-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-medium">Delete “{{ $bars['archive']['label'] }}”?</p>
        <p class="mt-1 text-amber-800">It moves to the Archived tab and can be restored any time. {{ $bars['archive']['warning'] }}</p>
        <div class="mt-2 flex gap-2">
            <button type="button" wire:click="confirmArchive" class="rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Yes, delete</button>
            <button type="button" wire:click="cancelArchive" class="rounded border bg-white px-3 py-1.5 text-xs hover:bg-gray-50">Cancel</button>
        </div>
    </div>
@endif

@if ($bars['force'])
    <div class="mb-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
        <p class="font-medium">Permanently delete “{{ $bars['force']['label'] }}”?</p>
        <p class="mt-1">This cannot be undone. If other records still reference it, it will be refused and stay archived.</p>
        <div class="mt-2 flex gap-2">
            <button type="button" wire:click="confirmForceDelete" class="rounded bg-red-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-800">Delete permanently</button>
            <button type="button" wire:click="cancelArchive" class="rounded border bg-white px-3 py-1.5 text-xs hover:bg-gray-50">Cancel</button>
        </div>
    </div>
@endif
