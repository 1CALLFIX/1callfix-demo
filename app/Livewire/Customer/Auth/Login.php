<?php

namespace App\Livewire\Customer\Auth;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\FirebaseAuthException;
use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Services\Auth\CustomerAccountResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Customer web login — identifier (mobile or email) + PASSWORD.
 *
 * The auth rebuild removed the recurring "enter your code" OTP step from
 * the login path entirely (it lives on only for signup / password reset /
 * Google linking). What is left here is a plain credential check on the
 * `web` session guard, plus the "Continue with Google" entry point.
 *
 * Rate limiting is mandatory and self-contained: Livewire actions bypass
 * routes/*.php `throttle:` middleware (one shared /livewire/update
 * endpoint), so without InteractsWithAuthThrottle this screen is an
 * un-throttled password-guessing surface. See that trait.
 *
 * An account that has no password yet (every pre-rebuild customer, who
 * only ever logged in by OTP) is not a dead end — it is redirected into
 * the one-time PasswordMigration flow.
 */
class Login extends Component
{
    use InteractsWithAuthThrottle;

    public string $identifier = '';

    public string $password = '';

    public string $error = '';

    public string $googleError = '';

    public function mount(): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirectRoute('customer.home', navigate: true);
        }
    }

    public function login(CustomerAccountResolver $accounts): void
    {
        $this->reset('error', 'googleError');

        $this->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'identifier.required' => 'Enter your mobile number or email.',
            'password.required' => 'Enter your password.',
        ]);

        if ($this->isThrottled('login', $this->identifier, maxPerIdentifier: 5)) {
            return;
        }

        $user = $accounts->findByLoginIdentifier($this->identifier);

        if ($user && blank($user->password)) {
            // Pre-rebuild OTP-only account — send them to set a password.
            $this->clearThrottle('login', $this->identifier);
            $this->redirectRoute('customer.auth.migrate', ['identifier' => trim($this->identifier)], navigate: true);

            return;
        }

        if (! $user || ! Hash::check($this->password, $user->password)) {
            $this->hitThrottle('login', $this->identifier);
            $this->error = 'Those details do not match an account.';
            $this->password = '';

            return;
        }

        $this->clearThrottle('login', $this->identifier);

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('customer.home', navigate: true);
    }

    #[On('firebase-error')]
    public function firebaseError(string $message): void
    {
        $this->googleError = $message;
    }

    /**
     * Called by resources/js/customer-auth.js after the Firebase Google
     * popup resolves. The browser only ever hands us the ID token; it is
     * re-verified here before a single claim is trusted.
     */
    #[On('firebase-google-token')]
    public function continueWithGoogle(string $idToken, FirebaseTokenVerifier $firebase, CustomerAccountResolver $accounts): void
    {
        $this->reset('error', 'googleError');

        if ($this->isThrottled('google', request()->ip(), maxPerIdentifier: 10)) {
            $this->googleError = $this->error;
            $this->error = '';

            return;
        }
        $this->hitThrottle('google', request()->ip());

        try {
            $identity = $firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->googleError = 'Could not verify that Google sign-in. Please try again.';

            return;
        }

        if (! $identity->isGoogleProvider()) {
            $this->googleError = 'That sign-in was not a Google account.';

            return;
        }

        $linked = $accounts->findByFirebaseUid($identity->uid);
        if ($linked) {
            $accounts->linkFirebaseIdentity($linked, $identity);
            Auth::guard('web')->login($linked);
            session()->regenerate();
            $this->redirectRoute('customer.home', navigate: true);

            return;
        }

        // Not linked yet — hand off to the mandatory-mobile-verification
        // flow. The verified identity is stored server-side (session),
        // never trusted from the browser again.
        session()->put('auth.google', [
            'uid' => $identity->uid,
            'email' => $identity->email,
            'name' => $identity->name,
            'picture' => $identity->picture,
            'email_verified' => $identity->emailVerified,
            'verified_at' => now()->timestamp,
        ]);

        $this->redirectRoute('customer.auth.google', navigate: true);
    }

    public function render()
    {
        return view('livewire.customer.auth.login')
            ->layout('components.layouts.customer', ['title' => 'Sign in']);
    }
}
