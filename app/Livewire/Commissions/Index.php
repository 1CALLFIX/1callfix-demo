<?php

namespace App\Livewire\Commissions;

use App\Exports\CommissionsExport;
use App\Models\Commission;
use App\Models\Franchise;
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
     * no zone_id/franchise_id of its own -- only booking_id -- so baseQuery()
     * scopes through the booking relation (booking.zone_id/booking.
     * franchise_id/booking.franchise.city_id/booking.franchise.country_id),
     * the same ancestry every other booking-derived screen this session
     * scoped uses.
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

    private function baseQuery()
    {
        $columns = ['zone_id' => 'booking.zone_id', 'franchise_id' => 'booking.franchise_id', 'city_id' => 'booking.franchise.city_id', 'country_id' => 'booking.franchise.country_id'];

        return app(AuthorizationService::class)
            ->scopeQuery(Commission::query(), auth()->user(), 'commissions.view', $columns)
            ->with(['booking.franchise', 'booking.provider.user'])
            ->when($this->search !== '', fn ($q) => $q->whereHas('booking', fn ($b) => $b
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('provider.user', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))))
            ->when($this->franchiseFilter, fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('franchise_id', $this->franchiseFilter)))
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
        ])->layout('layouts.admin', ['title' => 'Commissions']);
    }
}
