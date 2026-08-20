<?php

namespace App\Livewire\TaxiRides;

use App\Actions\AdminCancelTaxiRideAction;
use App\Actions\CreateTaxiRideAction;
use App\Models\Address;
use App\Models\TaxiRide;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/** Phase 22.6 (Taxi) admin screen — exact structural mirror of ParcelOrders\Manage. */
class Manage extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';
    public ?int $selectedRideId = null;

    public string $customerSearch = '';
    public ?int $selectedCustomerId = null;
    public ?int $selectedZoneId = null;
    public string $pickupAddressLine = '';
    public ?float $pickupLat = null;
    public ?float $pickupLng = null;
    public string $dropoffAddressLine = '';
    public ?float $dropoffLat = null;
    public ?float $dropoffLng = null;
    public string $paymentMethod = 'online';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('taxi_rides.view'), 403, 'You do not have permission to view Taxi rides.');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedRidesQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(TaxiRide::query(), auth()->user(), 'taxi_rides.view', $this->scopeColumns())
            ->with(['customer', 'assignedWorker.user', 'franchise', 'pickupAddress', 'dropoffAddress']);
    }

    public function getCustomerResultsProperty()
    {
        if (strlen($this->customerSearch) < 2) {
            return collect();
        }

        return User::where('role', 'customer')
            ->where(fn ($q) => $q->where('name', 'like', "%{$this->customerSearch}%")->orWhere('phone', 'like', "%{$this->customerSearch}%"))
            ->limit(10)
            ->get();
    }

    public function selectCustomer(int $userId): void
    {
        $this->selectedCustomerId = $userId;
        $this->customerSearch = User::find($userId)?->name ?? '';
    }

    public function createRide(CreateTaxiRideAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('taxi_rides.view'), 403);

        $this->validate([
            'selectedCustomerId' => 'required|integer',
            'selectedZoneId' => 'required|integer|exists:zones,id',
            'pickupAddressLine' => 'required|string',
            'pickupLat' => 'required|numeric',
            'pickupLng' => 'required|numeric',
            'dropoffAddressLine' => 'nullable|string',
            'paymentMethod' => 'required|in:online,wallet,cash',
        ]);

        $zone = Zone::with('franchise')->findOrFail($this->selectedZoneId);

        // Admin Command Center mission (Security audit) -- same bug class
        // already fixed on Vehicles/Equipment/Properties/Stores/
        // Accommodations/Products/AddOns/ParcelOrders' own create actions:
        // the zones dropdown is unscoped and mount()'s
        // hasPermissionAnywhere() is an any-scope check, so a zone-scoped
        // call-center actor holding taxi_rides.view only in Zone A could
        // otherwise create a taxi ride under any other zone/franchise
        // platform-wide. Bookings\Index::createBooking() already checks
        // scope this exact way -- this screen copied Bookings' overall
        // create-on-behalf-of-customer shape but missed that one check.
        if (! auth()->user()->hasPermission('taxi_rides.view', [
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ])) {
            $this->addError('selectedZoneId', 'You do not have permission to create a taxi ride in this zone.');
            return;
        }

        try {
            $pickup = Address::create([
                'user_id' => $this->selectedCustomerId, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                'label' => 'Pickup', 'address_line' => $this->pickupAddressLine, 'lat' => $this->pickupLat, 'lng' => $this->pickupLng,
            ]);

            $dropoffId = null;
            if ($this->dropoffAddressLine && $this->dropoffLat !== null && $this->dropoffLng !== null) {
                $dropoffId = Address::create([
                    'user_id' => $this->selectedCustomerId, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                    'label' => 'Dropoff', 'address_line' => $this->dropoffAddressLine, 'lat' => $this->dropoffLat, 'lng' => $this->dropoffLng,
                ])->id;
            }

            $ride = $action->execute([
                'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id, 'customer_id' => $this->selectedCustomerId,
                'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoffId,
                'payment_method' => $this->paymentMethod,
            ]);

            session()->flash('message', "Taxi ride {$ride->code} created.");
            $this->reset(['customerSearch', 'selectedCustomerId', 'selectedZoneId', 'pickupAddressLine', 'pickupLat', 'pickupLng', 'dropoffAddressLine', 'dropoffLat', 'dropoffLng']);
        } catch (\App\Exceptions\ModuleNotActiveException $e) {
            $this->addError('creation', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addError('creation', $e->getMessage());
        }
    }

    public function viewRide(int $rideId): void
    {
        $ride = $this->scopedRidesQuery()->find($rideId);
        abort_if(! $ride, 404, 'Taxi ride not found, or you do not have access to it.');

        $this->selectedRideId = $rideId;
    }

    public function backToList(): void
    {
        $this->selectedRideId = null;
    }

    public function cancelRide(AdminCancelTaxiRideAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('taxi_rides.cancel'), 403);

        $ride = $this->scopedRidesQuery()->find($this->selectedRideId);
        abort_if(! $ride, 404);

        try {
            $action->execute($ride->id, 'Cancelled by admin');
            session()->flash('message', 'Taxi ride cancelled.');
        } catch (\RuntimeException $e) {
            $this->addError('cancel', $e->getMessage());
        }
    }

    public function getSelectedRideProperty(): ?TaxiRide
    {
        if (! $this->selectedRideId) {
            return null;
        }

        return $this->scopedRidesQuery()->with('statusHistory')->find($this->selectedRideId);
    }

    public function render()
    {
        if ($this->selectedRide) {
            return view('livewire.taxi-rides.manage', ['ride' => $this->selectedRide])->layout('layouts.admin', ['title' => 'Taxi Rides']);
        }

        $zones = Zone::where('is_active', true)->orderBy('name')->get(['id', 'name', 'franchise_id']);

        $rides = $this->scopedRidesQuery()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.taxi-rides.manage', ['ride' => null, 'rides' => $rides, 'zones' => $zones])
            ->layout('layouts.admin', ['title' => 'Taxi Rides']);
    }
}
