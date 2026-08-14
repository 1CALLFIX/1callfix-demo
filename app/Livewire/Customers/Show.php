<?php

namespace App\Livewire\Customers;

use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\WalletService;
use Livewire\Component;

class Show extends Component
{
    public User $customer;
    public string $flashMessage = '';
    public string $flashType = 'success';

    /** customers.view was seeded (2026_08_11_049000) but never checked on this detail screen (only toggleSuspended() was gated, via customers.manage) -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(int $customerId)
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('customers.view'), 403, 'You do not have permission to view customers.');

        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        // ->first() + abort_if(), not findOrFail() -- see Bookings\Show::mount()'s
        // identical comment for why ModelNotFoundException isn't safe here.
        $customer = app(AuthorizationService::class)
            ->scopeQuery(User::query(), auth()->user(), 'customers.view', $columns)
            ->where('role', 'customer')
            ->with(['franchise', 'zone', 'addresses'])
            ->find($customerId);

        abort_if(! $customer, 404);

        $this->customer = $customer;
    }

    public function getWalletBalanceProperty(): float
    {
        return app(WalletService::class)->balance($this->customer);
    }

    public function getRecentBookingsProperty()
    {
        return $this->customer->bookings()->with(['service', 'provider.user'])->latest()->limit(20)->get();
    }

    public function toggleSuspended(): void
    {
        if (! auth()->user()->hasPermission('customers.manage', array_filter([
            'zone_id' => $this->customer->zone_id,
            'franchise_id' => $this->customer->franchise_id,
        ]))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this customer.';
            return;
        }

        $this->customer->status = $this->customer->status === 'suspended' ? 'active' : 'suspended';
        $this->customer->save();

        $this->flashType = 'success';
        $this->flashMessage = $this->customer->status === 'suspended' ? 'Customer suspended.' : 'Customer reactivated.';
    }

    public function render()
    {
        return view('livewire.customers.show')
            ->layout('layouts.admin', ['title' => 'Customer Detail']);
    }
}
