<div class="max-w-3xl">
    <a href="{{ route('admin.zones.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Zones</a>

    <h1 class="text-2xl font-bold mt-2 mb-1">{{ $zoneId ? 'Edit Zone' : 'New Zone' }}</h1>
    @if ($code)
        <div class="text-sm text-gray-500 mb-4">Code: <span class="font-mono">{{ $code }}</span> (auto-generated from name, editable in the database if you need something specific)</div>
    @endif

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    <div class="bg-white rounded-lg shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Franchise</label>
                <select wire:model="franchiseId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Select franchise</option>
                    @foreach ($franchises as $franchise)
                        <option value="{{ $franchise->id }}">{{ $franchise->name }} @if($franchise->code)({{ $franchise->code }})@endif</option>
                    @endforeach
                </select>
                @error('franchiseId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Zone name</label>
                <input type="text" wire:model="name" placeholder="e.g. Nellore Zone 1" class="w-full border rounded px-3 py-2 text-sm">
                @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Default dispatch radius (km)</label>
                <input type="number" step="1" wire:model="defaultDispatchRadiusKm" class="w-full border rounded px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1">Used by the dispatch engine's Haversine distance calc, not the boundary drawn below.</p>
                @error('defaultDispatchRadiusKm') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end pb-2">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="isActive" class="rounded"> Active
                </label>
            </div>
        </div>

        <div class="border-t pt-4">
            <label class="block text-sm font-medium mb-1">Zone boundary</label>
            <p class="text-xs text-gray-400 mb-2">Click <strong>Start Drawing</strong>, then click points on the map to trace the boundary, then <strong>Finish Boundary</strong> (needs at least 3 points). Redraw anytime — the last shape finished is what saves.</p>

            <div wire:ignore>
                <div class="flex items-center gap-2 mb-2">
                    <button type="button" id="zone-draw-start" class="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700">Start Drawing</button>
                    <button type="button" id="zone-draw-finish" class="px-3 py-1.5 bg-emerald-600 text-white rounded text-xs font-medium hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed" disabled>Finish Boundary</button>
                    <button type="button" id="zone-draw-clear" class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded text-xs font-medium hover:bg-gray-300">Clear</button>
                    <span id="zone-draw-status" class="text-xs text-gray-400"></span>
                </div>

                <div id="zone-map"
                     data-polygon="{{ $boundaryPolygonJson ?: '[]' }}"
                     data-livewire-id="{{ $this->getId() }}"
                     style="height: 420px; width: 100%; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                </div>
            </div>

            <input type="hidden" wire:model="boundaryPolygonJson" id="boundary_polygon_input">
            @error('boundaryPolygonJson') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <button wire:click="save" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">
            Save Zone
        </button>
    </div>
</div>
