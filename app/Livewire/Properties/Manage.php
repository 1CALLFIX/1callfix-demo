<?php

namespace App\Livewire\Properties;

use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Provider;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 22.7 (Property Rental) admin screen — the catalog-management
 * counterpart to `Services\Manage`. Row-scoped the same way every other
 * franchise-owned catalog screen in this codebase is.
 */
class Manage extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $providerId = null;
    public ?int $propertyTypeId = null;
    public ?int $zoneId = null;
    public string $name = '';
    public string $addressLine = '';
    public ?float $lat = null;
    public ?float $lng = null;
    public string $basePrice = '0';
    public string $maxGuests = '1';
    public string $bedrooms = '1';
    public string $bathrooms = '1';
    public string $beds = '1';
    public string $minimumNights = '1';

    public bool $showEditModal = false;
    public ?int $editPropertyId = null;
    public string $editName = '';
    public bool $editIsActive = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('properties.manage'), 403, 'You do not have permission to manage properties.');
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedPropertiesQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Property::query(), auth()->user(), 'properties.manage', $this->scopeColumns())
            ->with(['provider.user', 'propertyType', 'franchise']);
    }

    public function createProperty(): void
    {
        $this->validate([
            'providerId' => 'required|exists:providers,id',
            'propertyTypeId' => 'required|exists:property_types,id',
            'zoneId' => 'required|exists:zones,id',
            'name' => 'required|string|max:255',
            'addressLine' => 'required|string',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'basePrice' => 'required|numeric|min:0',
        ]);

        $zone = Zone::findOrFail($this->zoneId);

        Property::create([
            'provider_id' => $this->providerId, 'property_type_id' => $this->propertyTypeId,
            'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
            'name' => $this->name, 'address_line' => $this->addressLine, 'lat' => $this->lat, 'lng' => $this->lng,
            'base_price' => $this->basePrice, 'max_guests' => $this->maxGuests, 'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms, 'beds' => $this->beds, 'minimum_nights' => $this->minimumNights,
            'is_active' => true,
        ]);

        $this->reset(['providerId', 'propertyTypeId', 'zoneId', 'name', 'addressLine', 'lat', 'lng', 'basePrice', 'maxGuests', 'bedrooms', 'bathrooms', 'beds', 'minimumNights']);
        session()->flash('message', 'Property created.');
    }

    public function editProperty(int $propertyId): void
    {
        $property = $this->scopedPropertiesQuery()->find($propertyId);
        abort_if(! $property, 404);

        $this->editPropertyId = $property->id;
        $this->editName = $property->name;
        $this->editIsActive = $property->is_active;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $property = $this->scopedPropertiesQuery()->find($this->editPropertyId);
        abort_if(! $property, 404);

        $this->validate(['editName' => 'required|string|max:255']);

        $property->update(['name' => $this->editName, 'is_active' => $this->editIsActive]);

        $this->showEditModal = false;
        session()->flash('message', 'Property updated.');
    }

    public function render()
    {
        $properties = $this->scopedPropertiesQuery()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->latest('id')
            ->paginate(20);

        return view('livewire.properties.manage', [
            'properties' => $properties,
            'providers' => Provider::with('user')->get(),
            'propertyTypes' => PropertyType::where('is_active', true)->orderBy('name')->get(),
            'zones' => Zone::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.admin', ['title' => 'Properties']);
    }
}
