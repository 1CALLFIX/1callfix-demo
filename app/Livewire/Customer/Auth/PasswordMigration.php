<?php

namespace App\Livewire\Customer\Auth;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\FirebaseAuthException;
use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Models\User;
use App\Services\Auth\CustomerAccountResolver;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One-time "verify to set your password" for accounts that pre-date the
 * auth rebuild and therefore have no password (OTP-only was the only login
 * method until now). Reached automatically from Login when it resolves an
 * identifier to an account with a null password — not a new mechanism,
 * just Signup's "verify then set password" triggered from the login side.
 *
 * The customer must prove the account's own mobile number through Firebase
 * before a password can be set on it.
 */
class PasswordMigration extends Component
{
    use InteractsWithAuthThrottle;

    /** query-string seed from Login */
    public string $identifier = '';

    /** verify_phone | set_password */
    #[Locked]
    public string $step = 'verify_phone';

    #[Locked]
    public int $targetUserId = 0;

    #[Locked]
    public string $targetPhoneE164 = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $error = '';

    public string $status = '';

    public function mount(CustomerAccountResolver $accounts): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirectRoute('customer.home', navigate: true);

            return;
        }

        $user = $accounts->findByLoginIdentifier($this->identifier);

        // Only accounts that genuinely need this land here. Anything else
        // goes back to the normal login screen with no information leaked.
        if (! $user || filled($user->password) || blank($user->phone)) {
            session()->flash('status', 'Please sign in with your password.');
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }

        $this->targetUserId = $user->id;
        $this->targetPhoneE164 = PhoneNumber::e164((string) $user->phone);
        $this->status = 'Verify your mobile number to set a password for your account.';
    }

    public function sendPhoneCode(): void
    {
        $this->reset('error');

        if ($this->isThrottled('migrate', $this->targetPhoneE164, maxPerIdentifier: 5)) {
            return;
        }
        $this->hitThrottle('migrate', $this->targetPhoneE164);

        $this->dispatch('firebase-send-phone-otp', phone: $this->targetPhoneE164);
        $this->status = 'Enter the code sent to '.$this->targetPhoneE164.'.';
    }

    #[On('firebase-error')]
    public function firebaseError(string $message): void
    {
        $this->error = $message;
    }

    #[On('firebase-phone-token')]
    public function phoneTokenReceived(string $idToken, FirebaseTokenVerifier $firebase, CustomerAccountResolver $accounts): void
    {
        $this->reset('error');

        try {
            $identity = $firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->error = 'Could not verify that code. Request a new one.';

            return;
        }

        $user = User::find($this->targetUserId);
        if (! $user) {
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }

        if (! $identity->isPhoneProvider() || ! $accounts->phoneMatches($user, (string) $identity->phoneNumber)) {
            $this->error = 'That verification does not match your account.';

            return;
        }

        // Stash the uid for linking once the password is set.
        session()->put('auth.migrate_uid', $identity->uid);
        $this->step = 'set_password';
        $this->status = 'Mobile verified. Choose a password.';
    }

    public function savePassword(CustomerAccountResolver $accounts): void
    {
        $this->reset('error');

        if ($this->step !== 'set_password') {
            $this->error = 'Verify your mobile number first.';

            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'password.min' => 'Use at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $user = User::find($this->targetUserId);
        if (! $user || filled($user->password)) {
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }

        $accounts->setPassword($user, $this->password);
        $accounts->markPhoneVerified($user);

        if ($uid = session()->pull('auth.migrate_uid')) {
            $user->forceFill(['firebase_uid' => $user->firebase_uid ?: $uid])->save();
        }

        $this->clearThrottle('migrate', $this->targetPhoneE164);

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('customer.home', navigate: true);
    }

    public function render()
    {
        return view('livewire.customer.auth.password-migration')
            ->layout('components.layouts.customer', ['title' => 'Set your password']);
    }
}
