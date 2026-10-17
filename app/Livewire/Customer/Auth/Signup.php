<?php

namespace App\Livewire\Customer\Auth;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\FirebaseAuthException;
use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Services\Auth\CustomerAccountResolver;
use App\Services\Auth\FirebaseIdentity;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use App\Livewire\Customer\Concerns\RedirectsAfterLogin;
use Livewire\Component;
use App\Services\Auth\SignupContext;
use App\Services\Referrals\InvalidReferralCode;
use App\Services\Referrals\ReferralCapture;

/**
 * Customer signup — verify a mobile number (Firebase phone OTP, client
 * side), optionally verify an email address (custom email OTP), then set a
 * password. The password is how the account logs in from then on.
 *
 * Mobile is mandatory (the answered schema decision: `users.phone` stays
 * NOT NULL — no account without a verified phone). Email is optional and
 * secondary: if given it is verified here and becomes an additional login
 * identifier.
 *
 * If the mobile number already belongs to an incomplete / pre-rebuild
 * OTP-only account, CustomerAccountResolver::completeSignup() RESUMES that
 * row rather than creating a duplicate; if it belongs to a fully
 * registered account it refuses with a clear message.
 *
 * Trust boundary: the browser only ever posts a Firebase ID token or a
 * numeric email code — never a "verified" flag. Every token is
 * re-verified server-side; the email code is re-checked against the hashed
 * `otps` row. The verified results live in #[Locked] properties Livewire
 * signs and the browser cannot alter.
 */
class Signup extends Component
{
    use RedirectsAfterLogin;

    use InteractsWithAuthThrottle;

    /** phone | verify_phone | details */
    #[Locked]
    public string $step = 'phone';

    public string $phone = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $email = '';

    public string $emailCode = '';

    public bool $emailCodeSent = false;

    public string $error = '';

    public string $status = '';

    #[Locked]
    public string $verifiedPhoneE164 = '';

    #[Locked]
    public string $phoneFirebaseUid = '';

    #[Locked]
    public string $verifiedEmail = '';

    /**
     * EARN4 R2 — the referral code, pre-filled from /signup?ref=CODE and
     * editable. Only shown (and only captured) while referral.enabled is ON.
     */
    public string $referralCode = '';

    /** Inline "code not valid" message, shown before submit (R3). */
    public string $referralError = '';

    public const REFERRAL_SESSION_KEY = 'referral.signup_code';

    public function mount(ReferralCapture $referrals): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirectRoute('customer.home', navigate: true);

            return;
        }

        if (! $referrals->enabled()) {
            session()->forget(self::REFERRAL_SESSION_KEY);

            return;
        }

        $ref = trim((string) request()->query('ref', ''));
        if ($ref !== '') {
            // Kept for the Google path too (web Google signup starts from Login).
            session([self::REFERRAL_SESSION_KEY => mb_substr($ref, 0, 32)]);
        }
        $this->referralCode = (string) session(self::REFERRAL_SESSION_KEY, '');
        if ($this->referralCode !== '') {
            $this->updatedReferralCode($referrals);
        }
    }

    public function updatedReferralCode(ReferralCapture $referrals): void
    {
        $this->referralCode = mb_substr(trim($this->referralCode), 0, 32);
        $this->referralError = '';

        if (! $referrals->enabled()) {
            $this->referralCode = '';

            return;
        }

        session([self::REFERRAL_SESSION_KEY => $this->referralCode]);

        $phone = $this->verifiedPhoneE164 ?: ($this->phone ?: null);
        if ($this->referralCode !== '' && ! $referrals->referrerFor($this->referralCode, $phone)) {
            $this->referralError = ReferralCapture::INVALID_MESSAGE;
        }
    }

    // ─────────────────────────── Phone step ───────────────────────────────

    public function requestPhoneCode(): void
    {
        $this->reset('error', 'status');

        $this->validate([
            'phone' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
        ], ['phone.regex' => 'Enter a valid mobile number.']);

        if (! PhoneNumber::looksValid($this->phone)) {
            $this->addError('phone', 'Enter a valid 10-digit mobile number.');

            return;
        }

        if ($this->isThrottled('signup-phone', $this->phone, maxPerIdentifier: 5)) {
            return;
        }
        $this->hitThrottle('signup-phone', $this->phone);

        // Ask the browser to run Firebase signInWithPhoneNumber. The step
        // advances to code entry only once the SMS has really gone out
        // (#[On('firebase-phone-otp-sent')]); a failure comes back as
        // 'firebase-error' and leaves the user here with one honest error
        // instead of a "code sent" banner sitting next to it. In tests
        // (no JS) phoneTokenReceived() is called directly and this
        // handshake is bypassed.
        $this->dispatch('firebase-send-phone-otp', phone: PhoneNumber::e164($this->phone));
        $this->status = 'Sending a verification code to '.PhoneNumber::e164($this->phone).'…';
    }

    /** customer-auth.js confirms signInWithPhoneNumber actually sent the SMS. */
    #[On('firebase-phone-otp-sent')]
    public function phoneOtpSent(): void
    {
        if (blank($this->verifiedPhoneE164)) {
            $this->step = 'verify_phone';
            $this->error = '';
            $this->status = 'Enter the code we sent to '.PhoneNumber::e164($this->phone).'.';
        }
    }

    #[On('firebase-error')]
    public function firebaseError(string $message): void
    {
        $this->error = $message;
        // Clear any optimistic "sending…" / "code sent" line so the user
        // sees a single failure, not a green confirmation beside a red error.
        $this->status = '';
    }

    /** Invoked with the Firebase ID token once the SMS code is confirmed client-side. */
    #[On('firebase-phone-token')]
    public function phoneTokenReceived(string $idToken, FirebaseTokenVerifier $firebase): void
    {
        $this->reset('error');

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

        $this->verifiedPhoneE164 = $identity->phoneNumber;
        $this->phoneFirebaseUid = $identity->uid;
        $this->step = 'details';
        $this->status = 'Mobile number verified. Choose a password to finish.';
    }

    public function changePhone(): void
    {
        $this->reset('error', 'status', 'verifiedPhoneE164', 'phoneFirebaseUid');
        $this->step = 'phone';
    }

    // ─────────────────────── Optional email step ──────────────────────────

    public function sendEmailCode(OtpService $otp): void
    {
        $this->reset('error', 'status');

        $this->validate(['email' => ['required', 'email', 'max:255']]);

        if ($this->isThrottled('signup-email', $this->email, maxPerIdentifier: 5)) {
            return;
        }
        $this->hitThrottle('signup-email', $this->email);

        try {
            $otp->generate(Str::lower(trim($this->email)), OtpService::PURPOSE_EMAIL_VERIFY, request()->ip());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->emailCodeSent = true;
        $this->status = 'We sent a verification code to '.$this->email.'.';
    }

    public function verifyEmailCode(OtpService $otp): void
    {
        $this->reset('error', 'status');

        $this->validate(['emailCode' => ['required', 'string', 'digits_between:4,8']]);

        $result = $otp->verify(Str::lower(trim($this->email)), OtpService::PURPOSE_EMAIL_VERIFY, $this->emailCode);

        if (! $result['success']) {
            $this->error = match ($result['reason']) {
                'locked' => 'Too many incorrect attempts. Request a new code.',
                'expired' => 'This code has expired. Request a new one.',
                'not_found' => 'Request a code first.',
                default => 'Incorrect code.',
            };
            $this->emailCode = '';

            return;
        }

        $this->verifiedEmail = Str::lower(trim($this->email));
        $this->emailCode = '';
        $this->status = 'Email verified.';
    }

    // ─────────────────────────── Finish ───────────────────────────────────

    public function completeSignup(CustomerAccountResolver $accounts, ReferralCapture $referrals): void
    {
        $this->reset('error', 'status');

        if ($this->step !== 'details' || blank($this->verifiedPhoneE164)) {
            $this->error = 'Verify your mobile number first.';

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'password.min' => 'Use at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $verifiedEmail = null;
        if (filled($this->email)) {
            if ($this->verifiedEmail === '' || $this->verifiedEmail !== Str::lower(trim($this->email))) {
                $this->error = 'Verify your email address, or leave the field blank.';

                return;
            }
            $verifiedEmail = $this->verifiedEmail;
        }

        $identity = new FirebaseIdentity(
            uid: $this->phoneFirebaseUid,
            phoneNumber: $this->verifiedPhoneE164,
            email: null, emailVerified: false, name: null, picture: null,
            signInProvider: 'phone',
        );

        // EARN4 R3 — an invalid code never blocks signup: the inline message
        // was shown before submit; the account is simply created without one.
        try {
            $referrerId = $referrals->resolveForSignup($this->referralCode, $this->verifiedPhoneE164);
        } catch (InvalidReferralCode) {
            $referrerId = null;
        }

        try {
            ['user' => $user] = $accounts->completeSignup(
                $identity, $this->password, $this->name, $verifiedEmail,
                new SignupContext(request()->ip(), null, $referrerId),
            );
        } catch (AccountAlreadyExistsException $e) {
            $this->error = $e->getMessage().' Please sign in instead.';

            return;
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->error = 'Could not verify your mobile number. Start again.';
            $this->step = 'phone';

            return;
        }

        $this->clearThrottle('signup-phone', $this->phone);
        session()->forget(self::REFERRAL_SESSION_KEY);

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectAfterLogin();
    }

    public function render()
    {
        return view('livewire.customer.auth.signup', ['referralsOn' => app(ReferralCapture::class)->enabled()])
            ->layout('components.layouts.customer', ['title' => 'Create your account']);
    }
}
