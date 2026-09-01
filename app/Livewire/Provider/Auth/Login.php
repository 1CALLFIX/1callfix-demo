<?php

namespace App\Livewire\Provider\Auth;

use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Services\Auth\CustomerAccountResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * PHASE PW1 §2.3 — partner sign-in. Deliberately a thin variant of
 * App\Livewire\Customer\Auth\Login, not a second auth engine:
 *
 *   - same `web` session guard, same CustomerAccountResolver lookup, same
 *     InteractsWithAuthThrottle (Livewire actions bypass route throttling),
 *   - the password-migration fork for legacy password-less accounts is kept
 *     verbatim — a pre-rebuild provider sets a password through the same
 *     one-time flow, then returns here,
 *   - after a successful credential check it additionally refuses (and does
 *     NOT start a session for) a user with no `providers` row,
 *   - it redirects to the partner dashboard instead of the customer home.
 *
 * Google / Firebase-phone are intentionally out of P1 (every existing
 * provider has a password); the handler is a copy-paste from the customer
 * component when wanted.
 */
class Login extends Component
{
    use InteractsWithAuthThrottle;

    public string $identifier = '';

    public string $password = '';

    public string $error = '';

    public function mount(): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirectRoute(
                auth()->user()->providerProfile()->exists() ? 'provider.dashboard' : 'customer.home',
                navigate: true,
            );
        }
    }

    public function login(CustomerAccountResolver $accounts): void
    {
        $this->reset('error');

        $this->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'identifier.required' => 'Enter your mobile number or email.',
            'password.required' => 'Enter your password.',
        ]);

        if ($this->isThrottled('provider-login', $this->identifier, maxPerIdentifier: 5)) {
            return;
        }

        $user = $accounts->findByLoginIdentifier($this->identifier);

        if ($user && blank($user->password)) {
            $this->clearThrottle('provider-login', $this->identifier);
            $this->redirectRoute('customer.auth.migrate', ['identifier' => trim($this->identifier)], navigate: true);

            return;
        }

        if (! $user || ! Hash::check($this->password, $user->password)) {
            $this->hitThrottle('provider-login', $this->identifier);
            $this->error = 'Those details do not match an account.';
            $this->password = '';

            return;
        }

        if (! $user->providerProfile()->exists()) {
            $this->hitThrottle('provider-login', $this->identifier);
            $this->error = 'That account is not a registered service partner.';
            $this->password = '';

            return;
        }

        $this->clearThrottle('provider-login', $this->identifier);

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('provider.dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.provider.auth.login')
            ->layout('components.layouts.provider', ['title' => 'Partner sign in']);
    }
}
