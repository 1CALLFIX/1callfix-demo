<?php

namespace App\Livewire\Accommodations;

use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Models\HotelRatePlan;
use App\Models\HotelRoomType;
use App\Models\Provider;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * HOTEL / STAY BOOKING MODULE admin screen — the catalog-management
 * counterpart to `Properties\Manage`, extended to cover the room-type/
 * rate-plan/inventory layer this vertical actually needs. All four
 * (Accommodations, Room Types, Rate Plans, Room Inventory) live behind the
 * single `accommodations.manage` permission and this one screen — same
 * scope-per-permission ratio `properties.manage` already establishes for a
 * comparable catalog surface, not four separate permissions with no real
 * access-control distinction between them.
 */
class Manage extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $selectedAccommodationId = null;

    // New accommodation form
    public ?int $providerId = null;
    public ?int $accommodationTypeId = null;
    public ?int $zoneId = null;
    public string $name = '';
    public string $addressLine = '';
    public ?float $lat = null;
    public ?float $lng = null;

    // Edit accommodation modal
    public bool $showEditModal = false;
    public ?int $editAccommodationId = null;
    public string $editName = '';
    public bool $editIsActive = true;

    // New room type form
    public string $roomTypeName = '';
    public string $roomTypeMaxAdults = '2';
    public string $roomTypeMaxChildren = '0';
    public string $roomTypeTotalInventory = '1';

    // New rate plan form (per room type)
    public ?int $ratePlanRoomTypeId = null;
    public string $ratePlanName = '';
    public string $ratePlanMealPlan = 'room_only';
    public string $ratePlanCancellationLabel = 'flexible';
    public string $ratePlanNightlyRate = '0';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('accommodations.manage'), 403, 'You do not have permission to manage accommodations.');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedAccommodationsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Accommodation::query(), auth()->user(), 'accommodations.manage', $this->scopeColumns())
            ->with(['provider.user', 'accommodationType', 'franchise']);
    }

    public function createAccommodation(): void
    {
        $this->validate([
            'providerId' => 'required|exists:providers,id',
            'accommodationTypeId' => 'required|exists:accommodation_types,id',
            'zoneId' => 'required|exists:zones,id',
            'name' => 'required|string|max:255',
            'addressLine' => 'required|string',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $zone = Zone::findOrFail($this->zoneId);

        Accommodation::create([
            'provider_id' => $this->providerId, 'accommodation_type_id' => $this->accommodationTypeId,
            'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
            'name' => $this->name, 'address_line' => $this->addressLine, 'lat' => $this->lat, 'lng' => $this->lng,
            'is_active' => true,
        ]);

        $this->reset(['providerId', 'accommodationTypeId', 'zoneId', 'name', 'addressLine', 'lat', 'lng']);
        session()->flash('message', 'Accommodation created.');
    }

    public function editAccommodation(int $accommodationId): void
    {
        $accommodation = $this->scopedAccommodationsQuery()->find($accommodationId);
        abort_if(! $accommodation, 404);

        $this->editAccommodationId = $accommodation->id;
        $this->editName = $accommodation->name;
        $this->editIsActive = $accommodation->is_active;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $accommodation = $this->scopedAccommodationsQuery()->find($this->editAccommodationId);
        abort_if(! $accommodation, 404);

        $this->validate(['editName' => 'required|string|max:255']);

        $accommodation->update(['name' => $this->editName, 'is_active' => $this->editIsActive]);

        $this->showEditModal = false;
        session()->flash('message', 'Accommodation updated.');
    }

    // ============================== Room types + rate plans (nested, per selected accommodation) ==============================

    public function viewAccommodation(int $accommodationId): void
    {
        $accommodation = $this->scopedAccommodationsQuery()->find($accommodationId);
        abort_if(! $accommodation, 404);

        $this->selectedAccommodationId = $accommodationId;
    }

    public function backToList(): void
    {
        $this->selectedAccommodationId = null;
    }

    private function assertSelectedAccommodation(): Accommodation
    {
        $accommodation = $this->scopedAccommodationsQuery()->find($this->selectedAccommodationId);
        abort_if(! $accommodation, 404);

        return $accommodation;
    }

    public function createRoomType(): void
    {
        $accommodation = $this->assertSelectedAccommodation();

        $this->validate([
            'roomTypeName' => 'required|string|max:255',
            'roomTypeMaxAdults' => 'required|integer|min:1',
            'roomTypeMaxChildren' => 'required|integer|min:0',
            'roomTypeTotalInventory' => 'required|integer|min:1',
        ]);

        HotelRoomType::create([
            'accommodation_id' => $accommodation->id,
            'name' => $this->roomTypeName,
            'max_adults' => $this->roomTypeMaxAdults,
            'max_children' => $this->roomTypeMaxChildren,
            'total_inventory' => $this->roomTypeTotalInventory,
            'is_active' => true,
        ]);

        $this->reset(['roomTypeName', 'roomTypeMaxAdults', 'roomTypeMaxChildren', 'roomTypeTotalInventory']);
        $this->roomTypeMaxAdults = '2';
        $this->roomTypeMaxChildren = '0';
        $this->roomTypeTotalInventory = '1';
        session()->flash('message', 'Room type created.');
    }

    public function toggleRoomTypeActive(int $roomTypeId): void
    {
        $accommodation = $this->assertSelectedAccommodation();
        $roomType = HotelRoomType::where('accommodation_id', $accommodation->id)->findOrFail($roomTypeId);
        $roomType->update(['is_active' => ! $roomType->is_active]);
    }

    public function createRatePlan(): void
    {
        $accommodation = $this->assertSelectedAccommodation();

        $this->validate([
            'ratePlanRoomTypeId' => 'required|exists:hotel_room_types,id',
            'ratePlanName' => 'required|string|max:255',
            'ratePlanMealPlan' => 'required|in:room_only,breakfast,half_board,full_board',
            'ratePlanCancellationLabel' => 'required|in:flexible,non_refundable',
            'ratePlanNightlyRate' => 'required|numeric|min:0',
        ]);

        $roomType = HotelRoomType::where('accommodation_id', $accommodation->id)->findOrFail($this->ratePlanRoomTypeId);

        HotelRatePlan::create([
            'hotel_room_type_id' => $roomType->id,
            'name' => $this->ratePlanName,
            'meal_plan' => $this->ratePlanMealPlan,
            'cancellation_policy_label' => $this->ratePlanCancellationLabel,
            'nightly_rate' => $this->ratePlanNightlyRate,
            'is_active' => true,
        ]);

        $this->reset(['ratePlanRoomTypeId', 'ratePlanName', 'ratePlanMealPlan', 'ratePlanCancellationLabel', 'ratePlanNightlyRate']);
        $this->ratePlanMealPlan = 'room_only';
        $this->ratePlanCancellationLabel = 'flexible';
        $this->ratePlanNightlyRate = '0';
        session()->flash('message', 'Rate plan created.');
    }

    public function toggleRatePlanActive(int $ratePlanId): void
    {
        $accommodation = $this->assertSelectedAccommodation();
        $ratePlan = HotelRatePlan::whereHas('roomType', fn ($q) => $q->where('accommodation_id', $accommodation->id))->findOrFail($ratePlanId);
        $ratePlan->update(['is_active' => ! $ratePlan->is_active]);
    }

    public function render()
    {
        if ($this->selectedAccommodationId) {
            $accommodation = $this->assertSelectedAccommodation();
            $roomTypes = HotelRoomType::where('accommodation_id', $accommodation->id)->with('ratePlans')->orderBy('name')->get();

            return view('livewire.accommodations.manage', [
                'accommodation' => $accommodation, 'accommodations' => null, 'roomTypes' => $roomTypes,
            ])->layout('layouts.admin', ['title' => 'Accommodations']);
        }

        $accommodations = $this->scopedAccommodationsQuery()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->latest('id')
            ->paginate(20);

        return view('livewire.accommodations.manage', [
            'accommodation' => null, 'accommodations' => $accommodations, 'roomTypes' => null,
            'providers' => Provider::with('user')->get(),
            'accommodationTypes' => AccommodationType::where('is_active', true)->orderBy('name')->get(),
            'zones' => Zone::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.admin', ['title' => 'Accommodations']);
    }
}
