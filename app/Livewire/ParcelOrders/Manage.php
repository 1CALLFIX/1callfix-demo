<?php

namespace App\Livewire\ParcelOrders;

use App\Actions\AdminCancelParcelOrderAction;
use App\Actions\CreateParcelOrderAction;
use App\Models\Address;
use App\Models\ParcelOrder;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 22.4 (Parcel) admin screen. Deliberately focused, not a giant
 * dashboard: list + search/filter + detail (lifecycle history, assignment,
 * payment) + cancel + a lean creation panel (existing customer, two inline
 * quick-add addresses) — mirroring `Bookings\Index`'s own "Add New panel +
 * live list, no modal" shape, simplified for two addresses instead of one.
 *
 * Row-level scope via the same `AuthorizationService::scopeQuery()`
 * convention every other admin list screen in this codebase uses —
 * `parcel_orders` carries its own `franchise_id`/`zone_id` directly
 * (unlike Chat/Commissions, which have to reach through a `booking`
 * relation), so the column map here is even simpler than those.
 */
class Manage extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';
    public ?int $selectedOrderId = null;

    // --- Add New panel ---
    public string $customerSearch = '';
    public ?int $selectedCustomerId = null;
    public ?int $selectedZoneId = null;
    public string $pickupAddressLine = '';
    public ?float $pickupLat = null;
    public ?float $pickupLng = null;
    public string $dropoffAddressLine = '';
    public ?float $dropoffLat = null;
    public ?float $dropoffLng = null;
    public string $packageDescription = '';
    public ?float $packageWeightKg = null;
    public string $packageSize = 'small';
    public string $paymentMethod = 'online';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('parcel_orders.view'), 403, 'You do not have permission to view Parcel orders.');
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

    private function scopedOrdersQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(ParcelOrder::query(), auth()->user(), 'parcel_orders.view', $this->scopeColumns())
            ->with(['customer', 'assignedWorker.user', 'franchise', 'pickupAddress', 'dropoffAddress']);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
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

    public function createOrder(CreateParcelOrderAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('parcel_orders.view'), 403);

        $this->validate([
            'selectedCustomerId' => 'required|integer',
            'selectedZoneId' => 'required|integer|exists:zones,id',
            'pickupAddressLine' => 'required|string',
            'pickupLat' => 'required|numeric',
            'pickupLng' => 'required|numeric',
            'dropoffAddressLine' => 'required|string',
            'dropoffLat' => 'required|numeric',
            'dropoffLng' => 'required|numeric',
            'packageWeightKg' => 'nullable|numeric|min:0',
            'paymentMethod' => 'required|in:online,wallet,cash',
        ]);

        $zone = Zone::with('franchise')->findOrFail($this->selectedZoneId);

        // Admin Command Center mission (Security audit) -- same bug class
        // already fixed on Vehicles/Equipment/Properties/Stores/
        // Accommodations/Products/AddOns' own createX(): the zones dropdown
        // is unscoped and mount()'s hasPermissionAnywhere() is an any-scope
        // check, so a zone-scoped call-center actor holding
        // parcel_orders.view only in Zone A could otherwise create a parcel
        // order under any other zone/franchise platform-wide. Bookings\
        // Index::createBooking() already checks scope this exact way
        // (hasPermission('bookings.create', $this->zoneScope($zone))) --
        // this screen copied Bookings' overall create-on-behalf-of-customer
        // shape but missed that one check.
        if (! auth()->user()->hasPermission('parcel_orders.view', [
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ])) {
            $this->addError('selectedZoneId', 'You do not have permission to create a parcel order in this zone.');
            return;
        }

        try {
            $pickup = Address::create([
                'user_id' => $this->selectedCustomerId, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                'label' => 'Pickup', 'address_line' => $this->pickupAddressLine, 'lat' => $this->pickupLat, 'lng' => $this->pickupLng,
            ]);
            $dropoff = Address::create([
                'user_id' => $this->selectedCustomerId, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                'label' => 'Dropoff', 'address_line' => $this->dropoffAddressLine, 'lat' => $this->dropoffLat, 'lng' => $this->dropoffLng,
            ]);

            $order = $action->execute([
                'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id, 'customer_id' => $this->selectedCustomerId,
                'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
                'package_description' => $this->packageDescription ?: null,
                'package_weight_kg' => $this->packageWeightKg,
                'package_size' => $this->packageSize,
                'payment_method' => $this->paymentMethod,
            ]);

            session()->flash('message', "Parcel order {$order->code} created.");
            $this->reset(['customerSearch', 'selectedCustomerId', 'selectedZoneId', 'pickupAddressLine', 'pickupLat', 'pickupLng', 'dropoffAddressLine', 'dropoffLat', 'dropoffLng', 'packageDescription', 'packageWeightKg']);
        } catch (\App\Exceptions\ModuleNotActiveException $e) {
            $this->addError('creation', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addError('creation', $e->getMessage());
        }
    }

    public function viewOrder(int $orderId): void
    {
        $order = $this->scopedOrdersQuery()->find($orderId);
        abort_if(! $order, 404, 'Parcel order not found, or you do not have access to it.');

        $this->selectedOrderId = $orderId;
    }

    public function backToList(): void
    {
        $this->selectedOrderId = null;
    }

    public function cancelOrder(AdminCancelParcelOrderAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('parcel_orders.cancel'), 403);

        $order = $this->scopedOrdersQuery()->find($this->selectedOrderId);
        abort_if(! $order, 404);

        try {
            $action->execute($order->id, 'Cancelled by admin');
            session()->flash('message', 'Parcel order cancelled.');
        } catch (\RuntimeException $e) {
            $this->addError('cancel', $e->getMessage());
        }
    }

    public function getSelectedOrderProperty(): ?ParcelOrder
    {
        if (! $this->selectedOrderId) {
            return null;
        }

        return $this->scopedOrdersQuery()->with('statusHistory')->find($this->selectedOrderId);
    }

    public function render()
    {
        if ($this->selectedOrder) {
            return view('livewire.parcel-orders.manage', ['order' => $this->selectedOrder])->layout('layouts.admin', ['title' => 'Parcel Orders']);
        }

        $zones = Zone::where('is_active', true)->orderBy('name')->get(['id', 'name', 'franchise_id']);

        $orders = $this->scopedOrdersQuery()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.parcel-orders.manage', ['order' => null, 'orders' => $orders, 'zones' => $zones])
            ->layout('layouts.admin', ['title' => 'Parcel Orders']);
    }
}
