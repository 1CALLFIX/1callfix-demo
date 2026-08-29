<?php

namespace App\Livewire\Customer\Auth;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\FirebaseAuthException;
use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Services\Auth\CustomerAccountResolver;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Forgot password — identifier (mobile or email) → OTP via the matching
 * channel (Firebase phone token / custom email code) → set a new password.
 * Reuses exactly the Task 1 verification infrastructure; no separate
 * mechanism.
 *
 * Enumeration-safe: the email branch always reports "a code has been sent"
 * regardless of account existence; the phone branch only fails (generically)
 * at the final step if no account matches.
 */
class ForgotPassword extends Component
{
    use InteractsWithAuthThrottle;

    /** identifier | email_code | verify_phone | new_password */
    #[Locked]
    public string $step = 'identifier';

    public string $identifier = '';

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $error = '';

    public string $status = '';

    #[Locked]
    public string $channel = '';

    #[Locked]
    public ?int $resolvedUserId = null;

    public function mount(): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirectRoute('customer.home', navigate: true);
        }
    }

    public function submitIdentifier(OtpService $otp): void
    {
        $this->reset('error', 'status');

        $this->validate(['identifier' => ['required', 'string', 'max:255']]);

        if ($this->isThrottled('forgot', $this->identifier, maxPerIdentifier: 5)) {
            return;
        }
        $this->hitThrottle('forgot', $this->identifier);

        if (CustomerAccountResolver::isEmail($this->identifier)) {
            $this->channel = 'email';
            try {
                $otp->generate(Str::lower(trim($this->identifier)), OtpService::PURPOSE_PASSWORD_RESET, request()->ip());
            } catch (\RuntimeException $e) {
                $this->error = $e->getMessage();

                return;
            }
            $this->step = 'email_code';
            $this->status = 'If that email is registered, a reset code has been sent.';

            return;
        }

        // Phone — client drives the Firebase verification.
        $this->channel = 'phone';
        $this->dispatch('firebase-send-phone-otp', phone: PhoneNumber::e164($this->identifier));
        $this->step = 'verify_phone';
        $this->status = 'Enter the code sent to '.PhoneNumber::e164($this->identifier).'.';
    }

    public function verifyEmailCode(OtpService $otp, CustomerAccountResolver $accounts): void
    {
        $this->reset('error', 'status');

        $this->validate(['code' => ['required', 'string', 'digits_between:4,8']]);

        $result = $otp->verify(Str::lower(trim($this->identifier)), OtpService::PURPOSE_PASSWORD_RESET, $this->code);

        if (! $result['success']) {
            $this->error = match ($result['reason']) {
                'locked' => 'Too many incorrect attempts. Request a new code.',
                'expired' => 'This code has expired. Request a new one.',
                'not_found' => 'Request a code first.',
                default => 'Incorrect code.',
            };
            $this->code = '';

            return;
        }

        $user = $accounts->findByEmail($this->identifier);
        $this->resolvedUserId = $user?->id;
        $this->step = 'new_password';
        $this->status = 'Choose a new password.';
    }

    #[On('firebase-error')]
    public function firebaseError(string $message): void
    {
        $this->error = $message;
    }

    #[On('firebase-phone-token')]
    public function phoneTokenReceived(string $idToken, FirebaseTokenVerifier $firebase, CustomerAccountResolver $accounts): void
    {
        $this->reset('error', 'status');

        try {
            $identity = $firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->error = 'Could not verify that code. Request a new one.';

            return;
        }

        if (! $identity->isPhoneProvider()
            || PhoneNumber::national($this->identifier) !== PhoneNumber::national((string) $identity->phoneNumber)) {
            $this->error = 'That verification does not match this mobile number.';

            return;
        }

        $user = $accounts->findByPhone($this->identifier);
        $this->resolvedUserId = $user?->id;
        $this->step = 'new_password';
        $this->status = 'Choose a new password.';
    }

    public function setNewPassword(CustomerAccountResolver $accounts): void
    {
        $this->reset('error', 'status');

        if ($this->step !== 'new_password') {
            $this->error = 'Verify your identity first.';

            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'password.min' => 'Use at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $user = $this->resolvedUserId ? \App\Models\User::find($this->resolvedUserId) : null;

        if (! $user) {
            // Verified identity, but nothing to reset — do not disclose which.
            $this->error = 'Password reset could not be completed. Try signing up instead.';

            return;
        }

        $accounts->setPassword($user, $this->password);
        $user->tokens()->delete();

        $this->clearThrottle('forgot', $this->identifier);

        session()->flash('status', 'Password updated. Sign in with your new password.');
        $this->redirectRoute('customer.login', navigate: true);
    }

    public function render()
    {
        return view('livewire.customer.auth.forgot-password')
            ->layout('components.layouts.customer', ['title' => 'Reset your password']);
    }
}
