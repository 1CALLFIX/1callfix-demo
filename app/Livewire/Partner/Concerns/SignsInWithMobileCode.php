<?php

namespace App\Livewire\Partner\Concerns;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\FirebaseAuthException;
use App\Models\User;
use App\Support\PhoneNumber;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Join in 2 minutes (owner 2026-11-12: "every business should feel very easy to join"): every partner sign-in (partner
 * / hotel, seller / restaurant, rider) opens on "a code sent to your mobile" — the same Firebase phone check as sign-up,
 * re-verified on the server — so a partner never needs a password. ?with=password opens the password form instead.
 *
 * The using component keeps its own checks (suspended, right partner role, where to go) in finishSignIn(), shared by
 * its password sign-in, and uses InteractsWithAuthThrottle.
 */
trait SignsInWithMobileCode
{
    #[Url(as: 'with', except: 'code')]
    public string $mode = 'code';

    public string $phone = '';

    /** phone = number entry; verify = code entry (only once the SMS really went out). */
    public string $codeStep = 'phone';

    public string $status = '';

    abstract protected function finishSignIn(User $user): void;

    public function requestCode(): void
    {
        $this->reset('error', 'notAppliedYet', 'status');
        $this->validate(['phone' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/']], [
            'phone.required' => 'Enter your mobile number.', 'phone.regex' => 'Enter a valid mobile number.',
        ]);
        if (! PhoneNumber::looksValid($this->phone)) {
            $this->addError('phone', 'Enter a valid 10-digit mobile number.');

            return;
        }
        if ($this->isThrottled('partner-login-code', $this->phone, maxPerIdentifier: 5)) {
            return;
        }
        $this->hitThrottle('partner-login-code', $this->phone);
        $this->dispatch('firebase-send-phone-otp', phone: PhoneNumber::e164($this->phone));
        $this->status = 'Sending a code to '.PhoneNumber::e164($this->phone).'…';
    }

    #[On('firebase-phone-otp-sent')]
    public function codeSent(): void
    {
        $this->codeStep = 'verify';
        $this->status = 'Enter the code we sent to '.PhoneNumber::e164($this->phone).'.';
    }

    #[On('firebase-error')]
    public function firebaseError(string $message): void
    {
        $this->error = $message;
        $this->status = '';
    }

    /** The code is right (checked again here): sign in whoever owns this number, with the page's own checks. */
    #[On('firebase-phone-token')]
    public function phoneTokenReceived(string $idToken, FirebaseTokenVerifier $firebase): void
    {
        $this->reset('error', 'notAppliedYet');
        try {
            $identity = $firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->error = 'Could not verify that code. Request a new one.';

            return;
        }
        if (! $identity->isPhoneProvider() || blank($identity->phoneNumber)) {
            $this->error = 'That verification did not include a mobile number.';

            return;
        }
        $user = User::where('phone', PhoneNumber::national($identity->phoneNumber))->first();
        if (! $user) {
            $this->error = 'No partner account uses this number yet. Join in about 2 minutes on the partner page.';
            $this->notAppliedYet = true;

            return;
        }
        $this->clearThrottle('partner-login-code', $this->phone);
        $this->finishSignIn($user);
    }

    /** The identifier the throttles count (the typed mobile / email, or the mobile the code went to). */
    protected function signInKey(): string
    {
        return $this->identifier !== '' ? $this->identifier : $this->phone;
    }
}
