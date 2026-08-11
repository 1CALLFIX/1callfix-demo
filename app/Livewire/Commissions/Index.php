<?php

namespace App\Livewire\Commissions;

use App\Models\Commission;
use App\Models\Franchise;
use Livewire\Component;
use Livewire\WithPagination;

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

    public function updatingSearch() { $this->resetPage(); }
    public function updatingFranchiseFilter() { $this->resetPage(); }
    public function updatingFromDate() { $this->resetPage(); }
    public function updatingToDate() { $this->resetPage(); }

    private function baseQuery()
    {
        return Commission::with(['booking.franchise', 'booking.provider.user'])
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

        return view('livewire.commissions.index', [
            'commissions' => $commissions,
            'franchises' => Franchise::orderBy('name')->get(),
            'providerTotal' => (float) ($totals->provider_total ?? 0),
            'franchiseTotal' => (float) ($totals->franchise_total ?? 0),
            'platformTotal' => (float) ($totals->platform_total ?? 0),
        ])->layout('layouts.admin', ['title' => 'Commissions']);
    }
}
