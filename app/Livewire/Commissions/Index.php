<?php

namespace App\Livewire\Commissions;

use App\Exports\CommissionsExport;
use App\Models\Commission;
use App\Models\Franchise;
use App\Models\Setting;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/**
 * commissions has been a real, actively-written ledger since
 * CommissionService::applyForBooking() (every completed booking splits into
 * provider/franchise/platform shares here) with no admin browsing screen --
 * Payouts only covers disbursement, never displays the split itself.
 * Read-only, same reasoning as Wallet Ledger / Loyalty.
 */
class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $franchiseFilter = null;
    public string $fromDate = '';
    public string $toDate = '';

    protected $queryString = ['search', 'franchiseFilter', 'fromDate', 'toDate'];

    /**
     * commissions.view was seeded (2026_08_11_053000) specifically so this
     * screen could be gated, but nothing ever actually checked it -- ANY
     * authenticated admin-panel actor (anyone who clears EnsureHasAdminAccess,
     * e.g. a Support role with only banners.manage) could see every
     * franchise's full commission split. hasPermissionAnywhere(), not a
     * specific scope: this is a cross-franchise list with no per-row scope
     * filter (like every other Index screen in this codebase), so the gate
     * mirrors the existing "prerequisite, not full row-scoping" reasoning
     * AuthorizationService::canAnywhere() already documents for
     * Bookings\Index::createBooking().
     *
     * Row-level scoping (was deferred here, now closed): commissions carries
     * no zone_id/franchise_id of its own -- only ONE of booking_id/
     * parcel_order_id/taxi_ride_id/property_reservation_id/
     * marketplace_order_id -- so baseQuery() scopes through whichever
     * relation is actually set on a given row.
     *
     * **2026-08-17 hardening finding (same bug class as Payments\Index's own
     * fix, worse here -- not even the old dual-candidate form, purely
     * `booking.*`):** the four newer Orderable purposes were never added.
     * A franchise-scoped `commissions.view` grant saw ZERO parcel/taxi/
     * property/marketplace commission rows (fail-closed, not a leak), and
     * even a global/super_admin viewer who COULD see them got a screen that
     * displayed "—" for every column (booking/franchise/provider all
     * resolved through `$c->booking`, always null for these rows) plus a
     * PHP null-property-access warning per row. Fixed by resolving the
     * relevant order relation and its own earner generically per purpose:
     * `booking.provider`/`parcelOrder.assignedWorker`/`taxiRide.
     * assignedWorker`/`propertyReservation.property.provider`/
     * `marketplaceOrder.store.provider` -- exactly the same earner each
     * vertical's own CommissionService::applyForFieldWorkerOrder() call
     * already pays.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('commissions.view'), 403, 'You do not have permission to view commissions.');
    }

    /**
     * Mission Phase 14 (Operations Import/Export completeness) — the real
     * reference product's "Earnings" export, mapped onto this ledger.
     * Reuses the viewer's own row-level scope (see CommissionsExport's
     * docblock) — no separate permission check needed beyond mount()'s,
     * same convention as the catalog screens' existing Export buttons.
     */
    public function exportCommissions()
    {
        return Excel::download(new CommissionsExport(auth()->user()), 'commissions-'.now()->format('Y-m-d').'.xlsx');
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingFranchiseFilter() { $this->resetPage(); }
    public function updatingFromDate() { $this->resetPage(); }
    public function updatingToDate() { $this->resetPage(); }

    /**
     * purpose-shaped relation name => the order relation's own
     * franchise-scope path segment, for scopeColumns()/filters below.
     * RENTAL MODULE IMPLEMENTATION: `rentalReservation` added, the shared
     * Vehicle/Equipment engine's own Orderable relation -- same reasoning
     * as every prior addition here.
     */
    private const ORDER_RELATIONS = ['booking', 'parcelOrder', 'taxiRide', 'propertyReservation', 'marketplaceOrder', 'rentalReservation'];

    private function baseQuery()
    {
        $columns = [
            'zone_id' => array_map(fn ($r) => "{$r}.zone_id", self::ORDER_RELATIONS),
            'franchise_id' => array_map(fn ($r) => "{$r}.franchise_id", self::ORDER_RELATIONS),
            'city_id' => array_map(fn ($r) => "{$r}.franchise.city_id", self::ORDER_RELATIONS),
            'country_id' => array_map(fn ($r) => "{$r}.franchise.country_id", self::ORDER_RELATIONS),
        ];

        return app(AuthorizationService::class)
            ->scopeQuery(Commission::query(), auth()->user(), 'commissions.view', $columns)
            ->with([
                'booking.franchise.country', 'booking.provider.user',
                'parcelOrder.franchise.country', 'parcelOrder.assignedWorker.user',
                'taxiRide.franchise.country', 'taxiRide.assignedWorker.user',
                'propertyReservation.franchise.country', 'propertyReservation.property.provider.user',
                'marketplaceOrder.franchise.country', 'marketplaceOrder.store.provider.user',
                'rentalReservation.franchise.country', 'rentalReservation.rentable.provider.user',
            ])
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->whereHas('booking', fn ($b) => $b
                    ->where('code', 'like', "%{$this->search}%")
                    ->orWhereHas('provider.user', fn ($p) => $p->where('name', 'like', "%{$this->search}%")))
                    ->orWhereHas('parcelOrder', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('taxiRide', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('propertyReservation', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('marketplaceOrder', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('rentalReservation', fn ($o) => $o->where('code', 'like', "%{$this->search}%"));
            }))
            ->when($this->franchiseFilter, fn ($q) => $q->where(function ($w) {
                foreach (self::ORDER_RELATIONS as $relation) {
                    $w->orWhereHas($relation, fn ($o) => $o->where('franchise_id', $this->franchiseFilter));
                }
            }))
            ->when($this->fromDate !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->fromDate))
            ->when($this->toDate !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->toDate));
    }

    public function render()
    {
        // Totals computed BEFORE ->latest()->paginate() mutates the builder
        // with ORDER BY/LIMIT -- otherwise the sum would inherit the page
        // limit and undercount (the same bug caught in the Loyalty screen).
        $totals = $this->baseQuery()
            ->selectRaw('SUM(provider_commission) as provider_total')
            ->selectRaw('SUM(franchise_commission) as franchise_total')
            ->selectRaw('SUM(platform_commission) as platform_total')
            ->first();

        $commissions = $this->baseQuery()->latest()->paginate(25);

        // The filter dropdown itself must not offer a franchise whose
        // commissions the viewer can't actually see -- same permission,
        // Franchise's own id/city_id/country_id (+ zones.id for a zone-scoped
        // grant, via the franchise's own zones() relation).
        $franchiseColumns = ['franchise_id' => 'id', 'city_id' => 'city_id', 'country_id' => 'country_id', 'zone_id' => 'zones.id'];
        $franchises = app(AuthorizationService::class)
            ->scopeQuery(Franchise::query(), auth()->user(), 'commissions.view', $franchiseColumns)
            ->orderBy('name')->get();

        return view('livewire.commissions.index', [
            'commissions' => $commissions,
            'franchises' => $franchises,
            'providerTotal' => (float) ($totals->provider_total ?? 0),
            'franchiseTotal' => (float) ($totals->franchise_total ?? 0),
            'platformTotal' => (float) ($totals->platform_total ?? 0),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Commissions']);
    }
}
