<?php

namespace App\Livewire\Stores;

use App\Models\Provider;
use App\Models\Store;
use App\Models\Zone;
use App\Services\AuthorizationService;
use App\Support\Modules;
use Livewire\Component;
use Livewire\WithPagination;

/** Phase 24 (Marketplace Foundation) admin screen — the catalog-management counterpart to Properties\Manage. Row-scoped the same way. */
class Manage extends Component
{
    use WithPagination;

    public string $search = '';
    public string $moduleFilter = '';

    public ?int $providerId = null;
    public ?int $zoneId = null;
    public string $module = 'commerce';
    public string $name = '';
    public string $addressLine = '';
    public ?float $lat = null;
    public ?float $lng = null;
    public string $phone = '';

    public bool $showEditModal = false;
    public ?int $editStoreId = null;
    public string $editName = '';
    public bool $editIsActive = true;
    public bool $editIsOpen = true;
    public bool $editPrescriptionRequired = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('stores.manage'), 403, 'You do not have permission to manage stores.');
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedStoresQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Store::query(), auth()->user(), 'stores.manage', $this->scopeColumns())
            ->with(['provider.user', 'franchise']);
    }

    public function createStore(): void
    {
        $this->validate([
            'providerId' => 'required|exists:providers,id',
            'zoneId' => 'required|exists:zones,id',
            'module' => 'required|in:'.implode(',', array_keys(Modules::ALL)),
            'name' => 'required|string|max:255',
            'addressLine' => 'required|string',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $zone = Zone::with('franchise')->findOrFail($this->zoneId);

        // Same gap as the other catalog Manage screens -- the zones dropdown
        // is unscoped, so a zone-scoped actor holding stores.manage only in
        // Zone A could otherwise create a store under any other
        // zone/franchise platform-wide. edit/saveEdit were already safe
        // (scopedStoresQuery() 404s out-of-scope); create had no check.
        if (! auth()->user()->hasPermission('stores.manage', [
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ])) {
            $this->addError('zoneId', 'You do not have permission to create a store in this zone.');
            return;
        }

        Store::create([
            'provider_id' => $this->providerId, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
            'module' => $this->module, 'name' => $this->name, 'address_line' => $this->addressLine,
            'lat' => $this->lat, 'lng' => $this->lng, 'phone' => $this->phone ?: null,
            'is_active' => true, 'is_open' => true,
        ]);

        $this->reset(['providerId', 'zoneId', 'name', 'addressLine', 'lat', 'lng', 'phone']);
        session()->flash('message', 'Store created.');
    }

    public function editStore(int $storeId): void
    {
        $store = $this->scopedStoresQuery()->find($storeId);
        abort_if(! $store, 404);

        $this->editStoreId = $store->id;
        $this->editName = $store->name;
        $this->editIsActive = $store->is_active;
        $this->editIsOpen = $store->is_open;
        $this->editPrescriptionRequired = $store->prescription_required;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $store = $this->scopedStoresQuery()->find($this->editStoreId);
        abort_if(! $store, 404);

        $this->validate(['editName' => 'required|string|max:255']);

        $store->update([
            'name' => $this->editName, 'is_active' => $this->editIsActive,
            'is_open' => $this->editIsOpen, 'prescription_required' => $this->editPrescriptionRequired,
        ]);

        $this->showEditModal = false;
        session()->flash('message', 'Store updated.');
    }

    public function render()
    {
        $stores = $this->scopedStoresQuery()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->moduleFilter !== '', fn ($q) => $q->where('module', $this->moduleFilter))
            ->latest('id')
            ->paginate(20);

        return view('livewire.stores.manage', [
            'stores' => $stores,
            'providers' => Provider::with('user')->get(),
            'zones' => Zone::where('is_active', true)->orderBy('name')->get(),
            'modules' => Modules::ALL,
        ])->layout('layouts.admin', ['title' => 'Stores']);
    }
}
