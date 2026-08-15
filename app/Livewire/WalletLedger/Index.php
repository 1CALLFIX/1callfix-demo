<?php

namespace App\Livewire\WalletLedger;

use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * wallet_transactions has been a real, actively-written ledger since the
 * Wallet phase (WalletService::credit/debit, WalletTopUpService) with no
 * admin browsing screen. Read-only — same "the ledger is truth" reasoning
 * as everywhere else this ledger is touched; no manual admin adjustments
 * in v1, no permission split beyond .view since there's nothing to manage.
 */
class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public string $typeFilter = ''; // '' | credit | debit
    public string $statusFilter = ''; // '' | successful | pending | failed
    public string $fromDate = '';
    public string $toDate = '';

    protected $queryString = ['search', 'typeFilter', 'statusFilter', 'fromDate', 'toDate'];

    /**
     * wallets.view was seeded (2026_08_11_051000) but never checked -- see
     * Commissions\Index's identical fix for the full reasoning.
     *
     * Row-level scoping (was deferred here, now closed): wallet_transactions
     * carries no geography of its own -- only wallet_id -- so baseQuery()
     * scopes through wallet.user (the wallet owner's own zone_id/franchise_id
     * columns, plus wallet.user.franchise.city_id/country_id).
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('wallets.view'), 403, 'You do not have permission to view the wallet ledger.');
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingTypeFilter() { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }
    public function updatingFromDate() { $this->resetPage(); }
    public function updatingToDate() { $this->resetPage(); }

    private function baseQuery()
    {
        $columns = ['zone_id' => 'wallet.user.zone_id', 'franchise_id' => 'wallet.user.franchise_id', 'city_id' => 'wallet.user.franchise.city_id', 'country_id' => 'wallet.user.franchise.country_id'];

        return app(AuthorizationService::class)
            ->scopeQuery(WalletTransaction::query(), auth()->user(), 'wallets.view', $columns)
            ->with('wallet.user')
            ->when($this->search !== '', fn ($q) => $q->whereHas('wallet.user', fn ($w) => $w
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('is_credit', $this->typeFilter === 'credit'))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->fromDate !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->fromDate))
            ->when($this->toDate !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->toDate));
    }

    public function render()
    {
        $transactions = $this->baseQuery()->latest()->paginate(25);

        $totals = $this->baseQuery()
            ->selectRaw('SUM(CASE WHEN is_credit = 1 THEN amount ELSE 0 END) as total_credit')
            ->selectRaw('SUM(CASE WHEN is_credit = 0 THEN amount ELSE 0 END) as total_debit')
            ->first();

        return view('livewire.wallet-ledger.index', [
            'transactions' => $transactions,
            'totalCredit' => (float) ($totals->total_credit ?? 0),
            'totalDebit' => (float) ($totals->total_debit ?? 0),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Wallet Ledger']);
    }
}
