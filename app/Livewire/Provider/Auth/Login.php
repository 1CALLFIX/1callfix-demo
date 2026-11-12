<?php

namespace App\Livewire\Provider\Auth;

use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Livewire\Partner\Concerns\SignsInWithMobileCode;
use App\Models\User;
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
    use SignsInWithMobileCode;

    public string $identifier = '';

    public string $password = '';

    public string $error = '';

    /** Right password, but the account (a customer) has not sent a partner application yet: point to it, not a dead end. */
    public bool $notAppliedYet = false;

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
        $this->reset('error', 'notAppliedYet');

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

        $this->finishSignIn($user);
    }

    /** The same checks and destination for both ways in (password or mobile code). */
    protected function finishSignIn(User $user): void
    {
        // Enable/Disable Audit follow-up (LAUNCH-002 finding) — the
        // original suspension fix (f4d8d12) covered the admin panel and
        // the customer web login surfaces but never this one, since this
        // component didn't exist in its current form at the time. Same
        // inline pattern App\Livewire\Auth\Login (admin) uses rather than
        // the customer ChecksAccountSuspension trait — this screen, like
        // that one, has exactly one call site, so a shared trait would add
        // an indirection with nothing to share it with. Checked BEFORE the
        // providerProfile check below, same reasoning as admin's ordering:
        // "suspended" is a more accurate reason than "not a registered
        // service partner."
        if ($user->status === 'suspended') {
            $this->hitThrottle('provider-login', $this->signInKey());
            $this->error = 'This account has been suspended. Contact support for help.';
            $this->password = '';

            return;
        }

        if (! $user->providerProfile()->exists() && $user->canApplyAsPartner('service')) {
            $this->clearThrottle('provider-login', $this->signInKey());
            $this->notAppliedYet = true;
            $this->error = 'Your 1CallFix account has no partner application yet. Finish it below with the same mobile number; it takes about 2 minutes.';
            $this->password = '';

            return;
        }

        if (! $user->providerProfile()->exists()) {
            $this->hitThrottle('provider-login', $this->signInKey());
            $this->error = 'That account is not a registered service partner.';
            $this->password = '';

            return;
        }

        $this->clearThrottle('provider-login', $this->signInKey());

        Auth::guard('web')->login($user);
        session()->regenerate();

        // E-commerce Phase 1: a shop-only seller is a provider account too; their home is the seller portal.
        // Hotels H1: a hotel-only account's home is the hotel portal.
        $this->redirectRoute($user->holdsPartnerRole('service') ? 'provider.dashboard'
            : ($user->holdsPartnerRole('commerce') ? 'seller.dashboard' : ($user->holdsPartnerRole('hotel') ? 'hotel.dashboard' : 'seller.dashboard')), navigate: true);
    }

    public function render()
    {
        return view('livewire.provider.auth.login')
            ->layout('components.layouts.provider', ['title' => 'Partner sign in']);
    }
}
