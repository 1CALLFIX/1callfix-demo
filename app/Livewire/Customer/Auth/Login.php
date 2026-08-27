<?php

namespace App\Livewire\Customer\Auth;

use App\Models\Setting;
use App\Services\Auth\CustomerAccountResolver;
use App\Services\OtpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Customer web login — phone number, then OTP (Phase B).
 *
 * ── What this component does NOT do ───────────────────────────────────────
 * It does not generate, store, hash, compare, expire, or count attempts
 * against an OTP. Every one of those lives in App\Services\OtpService and
 * is reused here verbatim — the same engine routes/api.php's
 * /auth/otp/{request,verify} endpoints call. There is exactly ONE OTP
 * implementation in this codebase and this is a consumer of it, not a
 * second one. The plaintext code never reaches this component, this
 * component's state, or the browser: OtpService hands it straight to the
 * SmsAdapter and only the hash is persisted.
 *
 * Customer account provisioning is likewise delegated to the shared
 * App\Services\Auth\CustomerAccountResolver — the same object
 * API\AuthController uses — so a customer created by a web login and one
 * created by an API login are identical by construction.
 *
 * ── Session vs. token ─────────────────────────────────────────────────────
 * The API issues a Sanctum bearer token because its clients are headless.
 * This is a first-party server-rendered app on the standard `web` session
 * guard, so it calls Auth::guard('web')->login() and regenerates the
 * session id (fixation defence) instead. No token is minted here, and the
 * API's token flow is completely unaffected.
 *
 * ── Rate limiting ─────────────────────────────────────────────────────────
 * routes/api.php protects the OTP endpoints with throttle:5,1 and
 * throttle:10,1. Livewire actions do NOT go through those — every Livewire
 * component action in this application shares the single /livewire/update
 * endpoint, so route-level throttles on api.php are simply not in the
 * request path. Without the explicit limiter below, this screen would be an
 * un-throttled way to force real SMS cost and to brute-force a 6-digit
 * code, bypassing the protection the API already has. Uses the same
 * RateLimiter-facade pattern App\Livewire\Auth\Login (the admin screen)
 * already established for exactly this reason, and the same numeric limits
 * as the API routes rather than invented ones. Keyed per phone+IP so one
 * abuser cannot lock out a real customer sharing a NAT/carrier IP, and not
 * per-IP alone so a spray across many phones from one host is still slowed.
 */
class Login extends Component
{
    private const REQUEST_MAX_ATTEMPTS = 5;   // matches routes/api.php throttle:5,1
    private const VERIFY_MAX_ATTEMPTS = 10;   // matches routes/api.php throttle:10,1
    private const DECAY_SECONDS = 60;

    public string $phone = '';
    public string $code = '';

    /** 'phone' (collecting the number) or 'code' (collecting the OTP). */
    #[Locked]
    public string $step = 'phone';

    /** Seconds the client must wait before "Resend code" becomes available. */
    #[Locked]
    public int $resendAvailableIn = 0;

    public string $error = '';
    public string $status = '';

    public function mount(): void
    {
        if (Auth::guard('web')->check()) {
            $this->redirectRoute('customer.home', navigate: true);
        }
    }

    /** Step 1 — ask OtpService to send a login code to this phone. */
    public function requestCode(OtpService $otpService): void
    {
        $this->reset('error', 'status');

        $this->validate(
            ['phone' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/']],
            ['phone.regex' => 'Enter a valid mobile number.'],
        );

        if ($this->tooManyAttempts('otp-request', self::REQUEST_MAX_ATTEMPTS)) {
            return;
        }

        try {
            $otpService->generate($this->normalisedPhone(), 'login', request()->ip(), null);
        } catch (\RuntimeException $e) {
            // OtpService's resend-cooldown message. Safe to surface as-is —
            // it describes the timing of the caller's OWN immediately-prior
            // request and leaks nothing about account existence (the same
            // reasoning API\AuthController::requestOtp() records).
            $this->error = $e->getMessage();

            return;
        }

        RateLimiter::hit($this->throttleKey('otp-request'), self::DECAY_SECONDS);

        $this->step = 'code';
        $this->code = '';
        $this->resendAvailableIn = $this->resendCooldownSeconds();
        $this->status = 'We sent a verification code to '.$this->normalisedPhone().'.';
    }

    /** Resend is the same operation as the initial request — OtpService enforces its own cooldown. */
    public function resendCode(OtpService $otpService): void
    {
        $this->requestCode($otpService);
    }

    /** Step 2 — verify the code and, on success, open a web session. */
    public function verifyCode(OtpService $otpService, CustomerAccountResolver $customers): void
    {
        $this->reset('error', 'status');

        $this->validate(
            ['code' => ['required', 'string', 'digits_between:4,8']],
            ['code.digits_between' => 'Enter the code exactly as you received it.'],
        );

        if ($this->tooManyAttempts('otp-verify', self::VERIFY_MAX_ATTEMPTS)) {
            return;
        }

        RateLimiter::hit($this->throttleKey('otp-verify'), self::DECAY_SECONDS);

        $result = $otpService->verify($this->normalisedPhone(), 'login', $this->code);

        if (! $result['success']) {
            // Same reason -> message mapping API\AuthController::verifyOtp()
            // already uses, so a customer sees identical wording whichever
            // surface they logged in from.
            $this->error = match ($result['reason']) {
                'locked' => 'Too many incorrect attempts. Request a new code.',
                'expired' => 'This code has expired. Request a new one.',
                'not_found' => 'No pending verification for this phone. Request a code first.',
                default => 'Incorrect code.',
            };
            $this->code = '';

            return;
        }

        $user = $customers->resolve($this->normalisedPhone());

        RateLimiter::clear($this->throttleKey('otp-request'));
        RateLimiter::clear($this->throttleKey('otp-verify'));

        Auth::guard('web')->login($user);
        session()->regenerate();

        $this->redirectRoute('customer.home', navigate: true);
    }

    /** Back to step 1 so the customer can correct a mistyped number. */
    public function changePhone(): void
    {
        $this->reset('code', 'error', 'status');
        $this->step = 'phone';
        $this->resendAvailableIn = 0;
    }

    public function render()
    {
        return view('livewire.customer.auth.login')
            ->layout('components.layouts.customer', ['title' => 'Sign in']);
    }

    /**
     * Trailing/interior spacing and dashes are presentation, not identity —
     * stripped so "98765 43210" and "9876543210" are the same account
     * rather than two. Nothing else about the number is rewritten (no
     * country-code inference), because users.phone has no canonical format
     * enforced anywhere else in this codebase and inventing one here would
     * silently orphan existing rows.
     */
    private function normalisedPhone(): string
    {
        return preg_replace('/[\s\-]/', '', trim($this->phone)) ?? '';
    }

    private function throttleKey(string $action): string
    {
        return 'customer-'.$action.'|'.$this->normalisedPhone().'|'.request()->ip();
    }

    private function tooManyAttempts(string $action, int $maxAttempts): bool
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($action), $maxAttempts)) {
            return false;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($action));
        $this->error = "Too many attempts. Please try again in {$seconds} seconds.";

        return true;
    }

    private function resendCooldownSeconds(): int
    {
        return (int) Setting::get('auth.otp_resend_cooldown_seconds', 30);
    }
}
