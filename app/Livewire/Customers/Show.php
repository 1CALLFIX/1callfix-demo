<?php

namespace App\Livewire\Customers;

use App\Models\Setting;
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
            ->with(['franchise.country', 'zone', 'addresses'])
            ->find($customerId);

        abort_if(! $customer, 404);

        $this->customer = $customer;
    }

    public function getWalletBalanceProperty(): float
    {
        return app(WalletService::class)->balance($this->customer);
    }

    /** Phase 21 item TECH-2 -- matches Bookings\Show::getCurrencySymbolProperty() exactly. */
    public function getCurrencySymbolProperty(): string
    {
        return Setting::get('locale.currency_symbol', '₹');
    }

    public function getRecentBookingsProperty()
    {
        return $this->customer->bookings()->with(['service', 'provider.user', 'franchise.country'])->latest()->limit(20)->get();
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

        // REF 1CF-LAUNCH-004 — a suspended account must not keep using a
        // Sanctum token it already holds; auth:sanctum looks the token up
        // in the database on every request, so deleting it here makes that
        // rejection immediate, not just "the next login." Scoped to this
        // one user's own tokens() relation, so no other account is
        // affected. Only on the suspend transition — reactivating does not
        // need to touch tokens, the account simply logs in again.
        if ($this->customer->status === 'suspended') {
            $this->customer->tokens()->delete();
        }

        $this->flashType = 'success';
        $this->flashMessage = $this->customer->status === 'suspended' ? 'Customer suspended.' : 'Customer reactivated.';
    }

    public function render()
    {
        return view('livewire.customers.show')
            ->layout('layouts.admin', ['title' => 'Customer Detail']);
    }
}
