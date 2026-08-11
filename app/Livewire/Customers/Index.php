<?php

namespace App\Livewire\Customers;

use App\Models\User;
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

    public function updatingSearch() { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }

    public function render()
    {
        $customers = User::where('role', 'customer')
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
