<?php

namespace App\Livewire\Rider\Auth;

use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Services\Auth\CustomerAccountResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * Rider sign-in (`/rider/login`). Same password login, throttle and suspension check as the partner sign-in
 * (Provider\Auth\Login), but the account must be an active parcel rider. Riders sign up on /partners (the partner
 * sign-up, documents pending admin review) or are added by an admin (Admin → Partners → People → Add parcel rider, walk-ins).
 * A pending rider can sign in; the dashboard shows the review state and offers start once approved.
 */
class Login extends Component
{
    use InteractsWithAuthThrottle;

    public string $identifier = '';

    public string $password = '';

    public string $error = '';

    /** Right password, but the account (a customer) has not applied as a rider yet: point to the partner page. */
    public bool $notAppliedYet = false;

    public function mount(): void
    {
        if (Auth::guard('web')->check() && $this->isRider(auth()->user())) {
            $this->redirectRoute('rider.dashboard', navigate: true);
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

        if ($this->isThrottled('rider-login', $this->identifier, maxPerIdentifier: 5)) {
            return;
        }

        $user = $accounts->findByLoginIdentifier($this->identifier);

        if ($user && blank($user->password)) {
            $this->clearThrottle('rider-login', $this->identifier);
            $this->redirectRoute('customer.auth.migrate', ['identifier' => trim($this->identifier)], navigate: true);

            return;
        }

        if (! $user || ! Hash::check($this->password, $user->password)) {
            $this->hitThrottle('rider-login', $this->identifier);
            $this->error = 'Those details do not match an account.';
            $this->password = '';

            return;
        }

        if ($user->status === 'suspended') {
            $this->hitThrottle('rider-login', $this->identifier);
            $this->error = 'This account has been suspended. Contact support for help.';
            $this->password = '';

            return;
        }

        if (! $this->isRider($user) && $user->canApplyAsPartner('parcel')) {
            $this->clearThrottle('rider-login', $this->identifier);
            $this->notAppliedYet = true;
            $this->error = 'Your 1CallFix account has not applied as a rider yet. Apply on the partner page with the same mobile number; you keep this password.';
            $this->password = '';

            return;
        }

        if (! $this->isRider($user)) {
            $this->hitThrottle('rider-login', $this->identifier);
            $this->error = 'That account is not an active parcel rider.';
            $this->password = '';

            return;
        }

        $this->clearThrottle('rider-login', $this->identifier);

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('rider.dashboard', navigate: true);
    }

    private function isRider($user): bool
    {
        $worker = $user->fieldWorkerProfile;

        return $worker && $worker->is_active && $worker->hasCapability('parcel_rider');
    }

    public function render()
    {
        return view('livewire.rider.auth.login')
            ->layout('components.layouts.rider', ['title' => 'Rider sign in']);
    }
}
