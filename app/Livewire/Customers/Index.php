<?php

namespace App\Livewire\Customers;

use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\WalletService;
use Livewire\Component;
use Livewire\WithPagination;

/** Customers were only ever visible embedded in a Booking's detail page -- the first standalone list/management screen for them. */
class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

    protected $queryString = ['search', 'statusFilter'];

    /** customers.view was seeded (2026_08_11_049000) but never checked -- see Commissions\Index's identical fix for the full reasoning. Distinct from customers.manage, which already gates Customers\Show's suspend/reactivate action. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('customers.view'), 403, 'You do not have permission to view customers.');
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }

    public function render()
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $scoped = app(AuthorizationService::class)->scopeQuery(User::query(), auth()->user(), 'customers.view', $columns);

        $customers = $scoped->where('role', 'customer')
            ->when($this->search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->withCount('bookings')
            ->latest()
            ->paginate(20);

        $walletService = app(WalletService::class);
        $customers->getCollection()->transform(function ($c) use ($walletService) {
            $c->wallet_balance = $walletService->balance($c);
            return $c;
        });

        return view('livewire.customers.index', compact('customers'))
            ->layout('layouts.admin', ['title' => 'Customers']);
    }
}
