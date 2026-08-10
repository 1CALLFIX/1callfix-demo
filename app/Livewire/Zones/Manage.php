<?php

namespace App\Livewire\Zones;

use App\Models\Franchise;
use App\Models\Zone;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

// Same one-screen pattern as the catalog and Banners screens: add form pinned
// at the top (with the boundary map beside it, mirroring the reference
// panel's layout), live list below, edit in a modal, one route.
//
// Two deliberate differences from the other Manage screens:
//
// - No Reorder. Zones are geographic, not a merchandised list, and `zones`
//   has no sort_order column. Adding one to match the others would be a
//   migration in service of a control nobody would use; the list sorts by
//   name (or ID) instead.
//
// - The boundary map needs care. Each map's wrapper carries wire:ignore, and
//   the polygon is handed to JS as a data attribute rather than a Livewire
//   event, so the edit modal's map has its data present the moment it's
//   inserted. See public/js/zone-map.js.
class Manage extends Component
{
    use WithPagination;

    // --- "Add New" form ---
    public string $franchiseId = '';
    public string $name = '';
    public string $defaultDispatchRadiusKm = '8';
    public bool $isActive = true;
    /** Raw JSON [{lat,lng},…] written by the map JS. Matches the column shape. */
    public string $boundaryPolygonJson = '';

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editZoneId = null;
    public string $editFranchiseId = '';
    public string $editName = '';
    public string $editCode = '';
    public string $editDefaultDispatchRadiusKm = '8';
    public bool $editIsActive = true;
    public string $editBoundaryPolygonJson = '';

    // --- View details modal ---
    public bool $showViewModal = false;
    public ?int $viewZoneId = null;

    // --- Delete confirmation ---
    public ?int $confirmingDeleteId = null;
    public string $deleteBlockedReason = '';

    // --- List controls ---
    public string $search = '';
    public string $filterFranchise = '';
    public string $filterActive = '';
    public bool $showFilters = false;
    public string $sortField = 'name';
    public string $sortDirection = 'asc';
    public int $perPage = 10;

    public string $flashMessage = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterFranchise(): void { $this->resetPage(); }
    public function updatedFilterActive(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    // ============================= Add New =============================

    public function save(): void
    {
        $this->validate([
            'franchiseId' => ['required', 'exists:franchises,id'],
            'name' => ['required', 'string', 'max:255'],
            'defaultDispatchRadiusKm' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], [
            'franchiseId' => 'franchise',
            'defaultDispatchRadiusKm' => 'dispatch radius',
        ]);

        $points = $this->decodePolygon($this->boundaryPolygonJson);

        if ($points === null) {
            $this->addError('boundaryPolygonJson', 'Draw a boundary with at least 3 points on the map before saving.');
            return;
        }

        // `code` is left out on purpose — ZoneObserver auto-generates it from
        // the name (first 3 letters, numeric suffix on collision).
        Zone::create([
            'franchise_id' => $this->franchiseId,
            'name' => $this->name,
            'boundary_polygon' => $points,
            'center_lat' => $this->centroid($points, 'lat'),
            'center_lng' => $this->centroid($points, 'lng'),
            'default_dispatch_radius_km' => $this->defaultDispatchRadiusKm,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['name', 'boundaryPolygonJson', 'isActive']);
        $this->isActive = true;
        $this->defaultDispatchRadiusKm = '8';
        $this->flashMessage = 'Zone created.';
    }

    /** @return array<int,array{lat:float,lng:float}>|null null when unusable */
    private function decodePolygon(string $json): ?array
    {
        $points = json_decode($json, true);

        return (is_array($points) && count($points) >= 3) ? $points : null;
    }

    /**
     * Simple average-of-points centroid — good enough for a rough display /
     * fallback marker. The dispatch radius calc itself still runs off
     * provider<->customer Haversine distance, per ServiceMatchingJob.
     */
    private function centroid(array $points, string $key): float
    {
        return round(array_sum(array_column($points, $key)) / count($points), 7);
    }

    // ============================== Edit modal ==============================

    public function edit(int $zoneId): void
    {
        $zone = Zone::findOrFail($zoneId);

        $this->editZoneId = $zone->id;
        $this->editFranchiseId = (string) $zone->franchise_id;
        $this->editName = $zone->name;
        $this->editCode = $zone->code ?? '';
        $this->editDefaultDispatchRadiusKm = (string) $zone->default_dispatch_radius_km;
        $this->editIsActive = $zone->is_active;
        $this->editBoundaryPolygonJson = json_encode($zone->boundary_polygon ?? []);

        $this->resetValidation();
        $this->showViewModal = false;
        $this->showEditModal = true;
    }

    public function update(): void
    {
        $this->validate([
            'editFranchiseId' => ['required', 'exists:franchises,id'],
            'editName' => ['required', 'string', 'max:255'],
            'editDefaultDispatchRadiusKm' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], [
            'editFranchiseId' => 'franchise',
            'editDefaultDispatchRadiusKm' => 'dispatch radius',
        ]);

        $points = $this->decodePolygon($this->editBoundaryPolygonJson);

        if ($points === null) {
            $this->addError('editBoundaryPolygonJson', 'A zone needs a boundary of at least 3 points.');
            return;
        }

        $zone = Zone::findOrFail($this->editZoneId);
        $zone->update([
            'franchise_id' => $this->editFranchiseId,
            'name' => $this->editName,
            'boundary_polygon' => $points,
            'center_lat' => $this->centroid($points, 'lat'),
            'center_lng' => $this->centroid($points, 'lng'),
            'default_dispatch_radius_km' => $this->editDefaultDispatchRadiusKm,
            'is_active' => $this->editIsActive,
        ]);

        $this->showEditModal = false;
        $this->flashMessage = 'Zone updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetValidation();
    }

    public function toggleActive(int $zoneId): void
    {
        $zone = Zone::findOrFail($zoneId);
        $zone->update(['is_active' => ! $zone->is_active]);
    }

    // ========================= View details (read-only) =========================

    public function view(int $zoneId): void
    {
        $this->viewZoneId = $zoneId;
        $this->showViewModal = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewZoneId = null;
    }

    public function getViewingZoneProperty(): ?Zone
    {
        if (! $this->viewZoneId) {
            return null;
        }

        return Zone::with('franchise')->withCount(['providers', 'bookings'])->find($this->viewZoneId);
    }

    // ============================== Delete ==============================

    public function confirmDelete(int $zoneId): void
    {
        $zone = Zone::withCount(['providers', 'bookings'])->findOrFail($zoneId);

        // Providers and bookings both carry zone_id, and bookings are
        // permanent operational records — deleting the zone out from under
        // them would orphan live dispatch data. Refuse with a reason.
        $blockers = [];
        if ($zone->providers_count > 0) {
            $blockers[] = $zone->providers_count.' '.Str::plural('provider', $zone->providers_count);
        }
        if ($zone->bookings_count > 0) {
            $blockers[] = $zone->bookings_count.' '.Str::plural('booking', $zone->bookings_count);
        }

        $this->deleteBlockedReason = $blockers
            ? 'This zone still has '.implode(' and ', $blockers).' attached. Reassign those first, or just switch the zone off instead.'
            : '';

        $this->confirmingDeleteId = $zoneId;
    }

    public function deleteZone(): void
    {
        if (! $this->confirmingDeleteId || $this->deleteBlockedReason) {
            return;
        }

        Zone::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->flashMessage = 'Zone deleted.';
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
        $this->deleteBlockedReason = '';
    }

    // ============================== Sorting ==============================

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    private function baseQuery()
    {
        return Zone::query()
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('code', 'like', '%'.$this->search.'%');
            }))
            ->when($this->filterFranchise !== '', fn ($q) => $q->where('franchise_id', $this->filterFranchise))
            ->when($this->filterActive !== '', fn ($q) => $q->where('is_active', $this->filterActive === 'active'));
    }

    public function render()
    {
        $zones = $this->baseQuery()
            ->with('franchise')
            ->withCount(['providers', 'bookings'])
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.zones.manage', [
            'zones' => $zones,
            'franchises' => Franchise::orderBy('name')->get(['id', 'name', 'code']),
            'mapsConfigured' => (bool) config('services.google_maps.key'),
        ])->layout('layouts.admin', ['title' => 'Zones']);
    }
}
