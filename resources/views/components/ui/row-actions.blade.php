@props(['id', 'archived' => false, 'editHref' => null, 'editClick' => null, 'canManage' => false, 'canForce' => false, 'viewHref' => null, 'viewLabel' => 'View'])
{{-- REF 1CF-ADMIN-ROWACTIONS-001 — Edit / Delete (or Restore / Delete permanently on an archived row). --}}
<div class="flex flex-wrap items-center justify-end gap-1">
    @if ($viewHref)
        <a href="{{ $viewHref }}" class="rounded px-2 py-1 text-xs text-gray-700 hover:bg-gray-100">{{ $viewLabel }}</a>
    @endif

    @if (! $archived)
        @if ($canManage && $editHref)
            <a href="{{ $editHref }}" class="rounded px-2 py-1 text-xs text-indigo-700 hover:bg-indigo-50">Edit</a>
        @elseif ($canManage && $editClick)
            <button type="button" wire:click="{{ $editClick }}" class="rounded px-2 py-1 text-xs text-indigo-700 hover:bg-indigo-50">Edit</button>
        @endif
        @if ($canManage)
            <button type="button" wire:click="askArchive({{ $id }})" class="rounded px-2 py-1 text-xs text-red-700 hover:bg-red-50">Delete</button>
        @endif
    @else
        @if ($canManage)
            <button type="button" wire:click="restoreRow({{ $id }})" class="rounded px-2 py-1 text-xs text-green-700 hover:bg-green-50">Restore</button>
        @endif
        @if ($canForce)
            <button type="button" wire:click="askForceDelete({{ $id }})" class="rounded px-2 py-1 text-xs text-red-700 hover:bg-red-50">Delete permanently</button>
        @endif
    @endif
</div>
