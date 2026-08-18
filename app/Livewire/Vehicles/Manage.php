<?php

namespace App\Livewire\Vehicles;

use App\Models\Provider;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * RENTAL MODULE IMPLEMENTATION admin screen -- the catalog-management
 * counterpart to Properties\Manage, for Vehicle inventory.
 */
class Manage extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $providerId = null;
    public ?int $vehicleCategoryId = null;
    public ?int $zoneId = null;
    public string $make = '';
    public string $model = '';
    public array $supportedRentalModes = ['self_drive'];
    public string $basePrice = '0';
    public string $pricingUnit = 'daily';
    public string $depositAmount = '';

    public bool $showEditModal = false;
    public ?int $editVehicleId = null;
    public string $editMake = '';
    public string $editModel = '';
    public bool $editIsActive = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('vehicles.manage'), 403, 'You do not have permission to manage vehicles.');
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedVehiclesQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Vehicle::query(), auth()->user(), 'vehicles.manage', $this->scopeColumns())
            ->with(['provider.user', 'vehicleCategory', 'franchise']);
    }

    public function createVehicle(): void
    {
        $this->validate([
            'providerId' => 'required|exists:providers,id',
            'vehicleCategoryId' => 'required|exists:vehicle_categories,id',
            'zoneId' => 'required|exists:zones,id',
            'make' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'basePrice' => 'required|numeric|min:0',
            'pricingUnit' => 'required|in:hourly,daily,weekly,monthly',
        ]);

        $zone = Zone::with('franchise')->findOrFail($this->zoneId);

        // The zones dropdown is deliberately unscoped (same convention as
        // Franchises' Country/City selects), so mount()'s any-scope gate
        // alone isn't enough here -- a zone-scoped actor holding
        // vehicles.manage only in Zone A could otherwise create a vehicle
        // under any other zone/franchise platform-wide just by picking a
        // different option. edit/saveEdit were already safe (they fetch via
        // scopedVehiclesQuery()->find(), which 404s out-of-scope) -- this
        // was the one write path with no scope check at all.
        if (! auth()->user()->hasPermission('vehicles.manage', [
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ])) {
            $this->addError('zoneId', 'You do not have permission to create a vehicle in this zone.');
            return;
        }

        Vehicle::create([
            'provider_id' => $this->providerId, 'vehicle_category_id' => $this->vehicleCategoryId,
            'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
            'make' => $this->make, 'model' => $this->model,
            'supported_rental_modes' => array_values(array_filter($this->supportedRentalModes)) ?: ['self_drive'],
            'base_price' => $this->basePrice, 'pricing_unit' => $this->pricingUnit,
            'deposit_amount' => $this->depositAmount !== '' ? $this->depositAmount : null,
            'is_active' => true,
        ]);

        $this->reset(['providerId', 'vehicleCategoryId', 'zoneId', 'make', 'model', 'basePrice', 'depositAmount']);
        $this->supportedRentalModes = ['self_drive'];
        $this->pricingUnit = 'daily';
        session()->flash('message', 'Vehicle created.');
    }

    public function editVehicle(int $vehicleId): void
    {
        $vehicle = $this->scopedVehiclesQuery()->find($vehicleId);
        abort_if(! $vehicle, 404);

        $this->editVehicleId = $vehicle->id;
        $this->editMake = $vehicle->make;
        $this->editModel = $vehicle->model;
        $this->editIsActive = $vehicle->is_active;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $vehicle = $this->scopedVehiclesQuery()->find($this->editVehicleId);
        abort_if(! $vehicle, 404);

        $this->validate(['editMake' => 'required|string|max:255', 'editModel' => 'required|string|max:255']);

        $vehicle->update(['make' => $this->editMake, 'model' => $this->editModel, 'is_active' => $this->editIsActive]);

        $this->showEditModal = false;
        session()->flash('message', 'Vehicle updated.');
    }

    public function render()
    {
        $vehicles = $this->scopedVehiclesQuery()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('make', 'like', "%{$this->search}%")->orWhere('model', 'like', "%{$this->search}%")))
            ->latest('id')
            ->paginate(20);

        return view('livewire.vehicles.manage', [
            'vehicles' => $vehicles,
            'providers' => Provider::with('user')->get(),
            'vehicleCategories' => VehicleCategory::where('is_active', true)->orderBy('name')->get(),
            'zones' => Zone::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.admin', ['title' => 'Vehicles']);
    }
}
