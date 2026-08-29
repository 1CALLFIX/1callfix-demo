<?php

namespace App\Livewire\Customer\Auth;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\FirebaseAuthException;
use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Models\User;
use App\Services\Auth\CustomerAccountResolver;
use App\Services\Auth\FirebaseIdentity;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Second leg of "Continue with Google": Login::continueWithGoogle() has
 * already verified the Google ID token server-side and stashed the
 * identity in the session. A mobile number is operationally required for
 * every account regardless of how it was created, so this screen forces a
 * Firebase phone verification before the account is completed:
 *
 *   • new Google identity        → verify any mobile → create the account
 *   • Google email matches an     → verify THAT account's own mobile as
 *     existing (unlinked) account   proof of ownership → link Google to it
 *
 * Nothing is written to the database until the phone token arrives, so an
 * abandoned verification leaves no orphaned or partially-linked account.
 */
class GoogleAuth extends Component
{
    use InteractsWithAuthThrottle;

    /** new | link */
    #[Locked]
    public string $mode = 'new';

    /** verify_phone */
    #[Locked]
    public string $step = 'verify_phone';

    public string $phone = '';

    #[Locked]
    public string $lockedPhoneE164 = '';

    #[Locked]
    public ?int $linkUserId = null;

    public string $googleEmail = '';

    public string $googleName = '';

    public string $error = '';

    public string $status = '';

    public function mount(CustomerAccountResolver $accounts): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirectRoute('customer.home', navigate: true);

            return;
        }

        $g = session('auth.google');
        if (! is_array($g) || blank($g['uid'] ?? null) || ($g['verified_at'] ?? 0) < now()->subMinutes(15)->timestamp) {
            session()->forget('auth.google');
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }

        $this->googleEmail = (string) ($g['email'] ?? '');
        $this->googleName = (string) ($g['name'] ?? '');

        $linked = $accounts->findByFirebaseUid($g['uid']);
        if ($linked) {
            Auth::guard('web')->login($linked);
            session()->regenerate();
            session()->forget('auth.google');
            $this->redirectRoute('customer.home', navigate: true);

            return;
        }

        $existing = $this->googleEmail ? $accounts->findByEmail($this->googleEmail) : null;
        if ($existing) {
            $this->mode = 'link';
            $this->linkUserId = $existing->id;
            $this->lockedPhoneE164 = PhoneNumber::e164((string) $existing->phone);
            $this->status = 'An account already uses '.$this->googleEmail.'. Verify its mobile number to link Google sign-in.';
        } else {
            $this->mode = 'new';
            $this->status = 'Verify a mobile number to finish creating your account.';
        }
    }

    public function requestPhoneCode(): void
    {
        $this->reset('error');

        $target = $this->mode === 'link' ? $this->lockedPhoneE164 : PhoneNumber::e164($this->phone);

        if ($this->mode === 'new') {
            $this->validate([
                'phone' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
            ], ['phone.regex' => 'Enter a valid mobile number.']);
            if (! PhoneNumber::looksValid($this->phone)) {
                $this->addError('phone', 'Enter a valid 10-digit mobile number.');

                return;
            }
        }

        if ($this->isThrottled('google-phone', $target ?: request()->ip(), maxPerIdentifier: 5)) {
            return;
        }
        $this->hitThrottle('google-phone', $target ?: request()->ip());

        $this->dispatch('firebase-send-phone-otp', phone: $target);
        $this->status = 'Enter the code sent to '.$target.'.';
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

        $g = session('auth.google');
        if (! is_array($g) || blank($g['uid'] ?? null)) {
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }

        try {
            $phoneIdentity = $firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->error = 'Could not verify that code. Request a new one.';

            return;
        }

        if (! $phoneIdentity->isPhoneProvider() || blank($phoneIdentity->phoneNumber)) {
            $this->error = 'That verification did not include a mobile number.';

            return;
        }

        $google = new FirebaseIdentity(
            uid: $g['uid'],
            phoneNumber: null,
            email: $g['email'] ?? null,
            emailVerified: (bool) ($g['email_verified'] ?? false),
            name: $g['name'] ?? null,
            picture: $g['picture'] ?? null,
            signInProvider: 'google.com',
        );

        try {
            if ($this->mode === 'link') {
                $user = User::find($this->linkUserId);
                if (! $user) {
                    $this->redirectRoute('customer.login', navigate: true);

                    return;
                }
                if (! $accounts->phoneMatches($user, (string) $phoneIdentity->phoneNumber)) {
                    $this->error = 'That mobile number does not match the account for '.$this->googleEmail.'.';

                    return;
                }
                $accounts->linkFirebaseIdentity($user, $google);
                $accounts->markPhoneVerified($user);
            } else {
                $user = $accounts->createFromGoogle($google, $phoneIdentity, null);
            }
        } catch (AccountAlreadyExistsException $e) {
            $this->error = $e->getMessage().' Sign in with that account instead.';

            return;
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->error = 'Could not complete Google sign-in.';

            return;
        }

        session()->forget('auth.google');
        $this->clearThrottle('google-phone', $this->lockedPhoneE164 ?: request()->ip());

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('customer.home', navigate: true);
    }

    public function render()
    {
        return view('livewire.customer.auth.google-auth')
            ->layout('components.layouts.customer', ['title' => 'Continue with Google']);
    }
}
