@props([
    'polygon' => '[]',
    'model' => 'boundaryPolygonJson',
    'componentId',
    'height' => '320px',
])

{{-- Boundary map + its drawing controls, used by both the "Add New Zone"
     form and the edit modal on the Zones screen.

     wire:ignore is mandatory, not defensive: Google builds a large DOM tree
     inside the canvas, and Livewire's morphing would rip it out on any
     re-render, leaving a blank grey box.

     Controls are scoped by data-role inside this wrapper rather than using
     global ids — there are two of these on the page at once when the edit
     modal is open, and duplicate ids would have both maps driven by
     whichever button the browser matched first. --}}
<div wire:ignore
     data-zone-map
     data-livewire-id="{{ $componentId }}"
     data-model="{{ $model }}"
     data-polygon="{{ $polygon ?: '[]' }}">

    <div class="flex items-center gap-2 mb-2 flex-wrap">
        <button type="button" data-role="start"
                class="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700">Start Drawing</button>
        <button type="button" data-role="finish" disabled
                class="px-3 py-1.5 bg-emerald-600 text-white rounded text-xs font-medium hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed">Finish Boundary</button>
        <button type="button" data-role="clear"
                class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded text-xs font-medium hover:bg-gray-300">Clear</button>
        <span data-role="status" class="text-xs text-gray-400"></span>
    </div>

    <div data-role="canvas"
         style="height: {{ $height }}; width: 100%; border-radius: 0.5rem; border: 1px solid #e5e7eb;"></div>
</div>
