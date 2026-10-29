<?php

namespace App\Livewire\Seller\Auth;

use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Services\Auth\CustomerAccountResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * Seller sign-in (`/seller/login`, E-commerce Phase 1). Same password login, throttle and suspension check as the
 * rider and partner sign-ins, but the account must own a shop. Sellers sign up on /partners ("Seller or shop") or are
 * set up by an admin (Admin → Stores). A seller whose shop is still waiting for approval can sign in and add products.
 */
class Login extends Component
{
    use InteractsWithAuthThrottle;

    public string $identifier = '';

    public string $password = '';

    public string $error = '';

    /** Right password, but the account has not opened a shop yet: point to the partner page. */
    public bool $notAppliedYet = false;

    public function mount(): void
    {
        if (Auth::guard('web')->check() && auth()->user()->holdsPartnerRole('commerce')) {
            $this->redirectRoute('seller.dashboard', navigate: true);
        }
    }

    public function login(CustomerAccountResolver $accounts): void
    {
        $this->reset('error', 'notAppliedYet');

        $this->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'identifier.required' => 'Enter your mobile number or email.',
            'password.required' => 'Enter your password.',
        ]);

        if ($this->isThrottled('seller-login', $this->identifier, maxPerIdentifier: 5)) {
            return;
        }

        $user = $accounts->findByLoginIdentifier($this->identifier);

        if ($user && blank($user->password)) {
            $this->clearThrottle('seller-login', $this->identifier);
            $this->redirectRoute('customer.auth.migrate', ['identifier' => trim($this->identifier)], navigate: true);

            return;
        }

        if (! $user || ! Hash::check($this->password, $user->password)) {
            $this->hitThrottle('seller-login', $this->identifier);
            $this->error = 'Those details do not match an account.';
            $this->password = '';

            return;
        }

        if ($user->status === 'suspended') {
            $this->hitThrottle('seller-login', $this->identifier);
            $this->error = 'This account has been suspended. Contact support for help.';
            $this->password = '';

            return;
        }

        if (! $user->holdsPartnerRole('commerce')) {
            $this->clearThrottle('seller-login', $this->identifier);
            $this->notAppliedYet = $user->canApplyAsPartner('commerce');
            $this->error = $this->notAppliedYet
                ? 'Your account has not opened a shop yet. Apply on the partner page with the same mobile number; you keep this password.'
                : 'That account is not a seller.';
            $this->password = '';

            return;
        }

        $this->clearThrottle('seller-login', $this->identifier);

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('seller.dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.seller.auth.login')
            ->layout('components.layouts.seller', ['title' => 'Seller sign in']);
    }
}
