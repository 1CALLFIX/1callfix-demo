<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Models\FieldWorker;
use App\Models\HotelReservation;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\PropertyReservation;
use App\Models\Provider;
use App\Models\RentalReservation;
use App\Models\TaxiRide;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Admin Command Center mission, Phase 16 (Global Search / Command Palette)
 * — a real, explicitly-named gap: with ~45 admin screen directories and 43
 * nav items, an operator had no way to jump straight to "booking 123" or a
 * customer by phone without navigating the exact right vertical screen
 * first. Mounted once in layouts.admin (see that file's own diff),
 * triggered by Ctrl+K/Cmd+K via Livewire's own native wire:keydown
 * modifiers — no Alpine, no separate JS asset, same "dependency-free"
 * discipline x-ui.modal's own docblock already established for this
 * codebase (checked before building this).
 *
 * Scope discipline (mission's own explicit requirement): every group below
 * is gated by the SAME permission its own admin screen's mount() already
 * requires, and every query runs through the SAME AuthorizationService::
 * scopeQuery() row-level scope that screen's own render() already applies
 * — a search result can never surface a record the searcher couldn't
 * already see by navigating there directly. No group runs at all until the
 * query is at least 2 characters (debounced client-side via
 * wire:model.live.debounce), and every group is LIMIT 5, indexed columns
 * only (code/name/phone) — never an unbounded scan.
 *
 * Deliberately does NOT include Stores/Products/Marketplace catalog
 * entities, CMS, or growth/finance records in v1 — those don't share the
 * "look up one specific real-world record by a short natural identifier"
 * shape (code/name/phone) this palette is built around; Bookings/Parcel/
 * Taxi/Rental/Hotel/Marketplace orders and People (Customers/Providers/
 * Workers) are the ones the mission's own example queries name.
 */
class GlobalSearch extends Component
{
    public bool $open = false;
    public string $query = '';

    public function openPalette(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->reset('query');
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    /** Order/reservation-type verticals: search by `code`, scoped identically to that vertical's own admin screen, linking to its index (none of these six have a per-record Show route — same reality the Operations dispatch-health cards already link against). */
    private function searchByCode(string $permission, string $modelClass, string $codeLabel, string $routeName): Collection
    {
        if (! auth()->user()->hasPermissionAnywhere($permission)) {
            return collect();
        }

        return app(AuthorizationService::class)
            ->scopeQuery($modelClass::query(), auth()->user(), $permission, $this->scopeColumns())
            ->where('code', 'like', '%'.$this->query.'%')
            ->limit(5)->get()
            ->map(fn ($row) => ['label' => "{$codeLabel} {$row->code}", 'route' => route($routeName)]);
    }

    /** People (Customers/Providers/Workers): search by the linked User's name/phone, scoped identically to that vertical's own admin screen, linking to its own Show route. */
    private function searchPeople(string $permission, string $modelClass, string $nounSingular, string $routeName, ?string $userRelation, ?string $roleFilter = null): Collection
    {
        if (! auth()->user()->hasPermissionAnywhere($permission)) {
            return collect();
        }

        $q = app(AuthorizationService::class)->scopeQuery($modelClass::query(), auth()->user(), $permission, $this->scopeColumns());

        if ($roleFilter) {
            $q->where('role', $roleFilter);
        }

        if ($userRelation) {
            $q->whereHas($userRelation, fn ($w) => $w->where('name', 'like', '%'.$this->query.'%')->orWhere('phone', 'like', '%'.$this->query.'%'))
                ->with($userRelation);
        } else {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$this->query.'%')->orWhere('phone', 'like', '%'.$this->query.'%'));
        }

        return $q->limit(5)->get()->map(function ($row) use ($userRelation, $nounSingular, $routeName) {
            $user = $userRelation ? $row->{$userRelation} : $row;

            return [
                'label' => ($user->name ?? "{$nounSingular} #{$row->id}").' — '.($user->phone ?? '—'),
                'route' => route($routeName, $row->id),
            ];
        });
    }

    public function getResultsProperty(): array
    {
        if (mb_strlen(trim($this->query)) < 2) {
            return [];
        }

        $groups = [];

        // Bookings gets its own block (not searchByCode) because it's the
        // one vertical here with a real per-record Show route.
        if (auth()->user()->hasPermissionAnywhere('bookings.view')) {
            $groups['Bookings'] = app(AuthorizationService::class)
                ->scopeQuery(Booking::query(), auth()->user(), 'bookings.view', $this->scopeColumns())
                ->where('code', 'like', '%'.$this->query.'%')
                ->limit(5)->get()
                ->map(fn ($b) => ['label' => "Booking {$b->code}", 'route' => route('admin.bookings.show', $b->id)]);
        }

        $groups['Customers'] = $this->searchPeople('customers.view', User::class, 'Customer', 'admin.customers.show', null, 'customer');
        $groups['Providers'] = $this->searchPeople('providers.view', Provider::class, 'Provider', 'admin.providers.show', 'user');
        $groups['Workers'] = $this->searchPeople('workers.view', FieldWorker::class, 'Worker', 'admin.workers.show', 'user');
        $groups['Parcel Orders'] = $this->searchByCode('parcel_orders.view', ParcelOrder::class, 'Parcel', 'admin.parcel-orders.index');
        $groups['Taxi Rides'] = $this->searchByCode('taxi_rides.view', TaxiRide::class, 'Taxi', 'admin.taxi-rides.index');
        $groups['Property Reservations'] = $this->searchByCode('property_reservations.view', PropertyReservation::class, 'Reservation', 'admin.property-reservations.index');
        $groups['Rental Reservations'] = $this->searchByCode('rental_reservations.view', RentalReservation::class, 'Reservation', 'admin.rental-reservations.index');
        $groups['Hotel Reservations'] = $this->searchByCode('hotel_reservations.view', HotelReservation::class, 'Reservation', 'admin.hotel-reservations.index');
        $groups['Marketplace Orders'] = $this->searchByCode('marketplace_orders.view', MarketplaceOrder::class, 'Order', 'admin.marketplace-orders.index');

        return array_filter($groups, fn ($rows) => $rows->isNotEmpty());
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
