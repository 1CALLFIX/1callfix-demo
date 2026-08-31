<?php

namespace App\Http\Controllers\API;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\FirebaseAuthException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CustomerAccountResolver;
use App\Services\Auth\FirebaseIdentity;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Authentication for Customer / Partner / Worker.
 *
 * ── Auth rebuild ─────────────────────────────────────────────────────────
 * OTP is no longer a login mechanism. Login is:
 *   • POST /auth/password  — identifier (mobile or email) + password
 *   • POST /auth/firebase  — a verified Firebase ID token (phone-auth or
 *     Google), which also carries signup and Google-account-linking
 *
 * OTP survives only as a verification step:
 *   • phone verification is done by Firebase (client-side; the ID token is
 *     re-verified here through App\Contracts\FirebaseTokenVerifier)
 *   • email verification / password reset by email use the custom numeric
 *     code engine, App\Services\OtpService, reached via the DEMOTED
 *     POST /auth/otp/{request,verify} — which now never issue a token.
 *
 * See docs/auth-otp-consumer-audit.md for the full migration record.
 * CustomerAccountResolver is the single shared provisioning point for this
 * controller and the Livewire web session flow.
 */
class AuthController extends Controller
{
    private const ACTOR_TYPES = ['customer', 'provider', 'field_worker'];

    public function __construct(
        private readonly CustomerAccountResolver $accounts,
        private readonly FirebaseTokenVerifier $firebase,
        private readonly OtpService $otp,
    ) {}

    // ═══════════════════════════ Password login ═══════════════════════════

    /**
     * POST /api/auth/password  { identifier, password, actor_type }
     * identifier is a mobile number OR an email address.
     */
    public function password(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'actor_type' => ['required', 'string', 'in:'.implode(',', self::ACTOR_TYPES)],
        ]);

        $key = 'api-auth-password|'.Str::lower($data['identifier']).'|'.$request->ip();
        $this->assertNotThrottled($key, 5);

        $user = $this->accounts->findByLoginIdentifier($data['identifier']);

        if ($user && blank($user->password)) {
            // A pre-rebuild OTP-only account. Route to the one-time
            // verify-and-set-password flow rather than a dead end.
            return response()->json([
                'message' => 'This account has no password yet. Verify your mobile number to set one.',
                'needs_password_setup' => true,
            ], 409);
        }

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $this->actorMatches($user, $data['actor_type'])) {
            RateLimiter::hit($key, 60);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        RateLimiter::clear($key);

        return $this->tokenResponse($user, $data['actor_type'], $request);
    }

    // ═══════════════════════ Firebase (login / signup / Google) ═══════════

    /**
     * POST /api/auth/firebase
     * {
     *   id_token,            // Firebase ID token: phone-auth OR Google
     *   actor_type,
     *   google_id_token?,    // second token, for the Google + phone step
     *   name?, password?,    // for a fresh signup
     * }
     */
    public function firebase(Request $request)
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'actor_type' => ['required', 'string', 'in:'.implode(',', self::ACTOR_TYPES)],
            'google_id_token' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $this->assertNotThrottled('api-auth-firebase|'.$request->ip(), 20);
        RateLimiter::hit('api-auth-firebase|'.$request->ip(), 60);

        $identity = $this->verifyOrFail($data['id_token']);

        // ── Google-provider token ────────────────────────────────────────
        if ($identity->isGoogleProvider() && ! filled($data['google_id_token'] ?? null)) {
            return $this->handleGoogleFirstLeg($identity, $data['actor_type'], $request);
        }

        // ── Google + phone second leg ────────────────────────────────────
        if (filled($data['google_id_token'] ?? null)) {
            $google = $this->verifyOrFail($data['google_id_token']);
            if (! $google->isGoogleProvider()) {
                return response()->json(['message' => 'The Google token is not a Google sign-in.'], 422);
            }
            if (! $identity->isPhoneProvider()) {
                return response()->json(['message' => 'A verified mobile number is required to finish Google sign-up.'], 422);
            }

            return $this->linkOrCreateGoogleAccount($google, $identity, $data['password'] ?? null, $data['actor_type'], $request);
        }

        // ── Phone-auth token ─────────────────────────────────────────────
        if (! $identity->isPhoneProvider()) {
            return response()->json(['message' => 'Unsupported sign-in provider.'], 422);
        }

        $user = $this->accounts->findForFirebaseIdentity($identity);

        // An existing account WITH a password → plain login.
        if ($user && filled($user->password)) {
            if (! $this->actorMatches($user, $data['actor_type'])) {
                return response()->json(['message' => $this->noProfileMessage($data['actor_type'])], 404);
            }
            $this->accounts->linkFirebaseIdentity($user, $identity);

            return $this->tokenResponse($user, $data['actor_type'], $request);
        }

        // No account, OR a password-less shell (pre-registered import /
        // pre-rebuild OTP-only row). Either way this is a customer signup
        // completing — provider/worker must be pre-created with a password.
        if ($data['actor_type'] !== 'customer') {
            return response()->json(['message' => $this->noProfileMessage($data['actor_type'])], 404);
        }

        if (blank($data['name'] ?? null) || blank($data['password'] ?? null)) {
            return response()->json([
                'message' => 'No usable account for this mobile number. Provide a name and password to finish registering.',
                'needs_registration' => true,
            ], 404);
        }

        // completeSignup() resumes a password-less shell in place, or creates a fresh row.
        return $this->registerCustomer($identity, $data['name'], $data['password'], null, $request);
    }

    /**
     * POST /api/auth/register  { id_token, name, password, email?, actor_type=customer }
     * Explicit signup alias — the phone must already be Firebase-verified.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $this->assertNotThrottled('api-auth-register|'.$request->ip(), 10);
        RateLimiter::hit('api-auth-register|'.$request->ip(), 60);

        $identity = $this->verifyOrFail($data['id_token']);
        if (! $identity->isPhoneProvider()) {
            return response()->json(['message' => 'A verified mobile number is required to register.'], 422);
        }

        // If an email is supplied it must itself have been verified through
        // the email OTP first (purpose email_verify, status verified).
        $verifiedEmail = null;
        if (filled($data['email'] ?? null)) {
            if (! $this->emailWasVerified($data['email'])) {
                return response()->json(['message' => 'Verify your email address before registering it.'], 422);
            }
            $verifiedEmail = $data['email'];
        }

        return $this->registerCustomer($identity, $data['name'], $data['password'], $verifiedEmail, $request, 201);
    }

    // ═══════════════════════════ Password reset ═══════════════════════════

    /** POST /api/auth/password/forgot  { identifier } */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']]);

        $this->assertNotThrottled('api-auth-forgot|'.Str::lower($data['identifier']).'|'.$request->ip(), 5);
        RateLimiter::hit('api-auth-forgot|'.Str::lower($data['identifier']).'|'.$request->ip(), 60);

        if (CustomerAccountResolver::isEmail($data['identifier'])) {
            // Send regardless of account existence — enumeration-safe.
            try {
                $this->otp->generate(Str::lower(trim($data['identifier'])), OtpService::PURPOSE_PASSWORD_RESET, $request->ip());
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 429);
            }

            return response()->json(['message' => 'If that email is registered, a reset code has been sent.', 'channel' => 'email']);
        }

        // Phone: the client drives a Firebase phone verification, then calls
        // /password/reset with the resulting id_token.
        return response()->json(['message' => 'Verify your mobile number to reset your password.', 'channel' => 'firebase']);
    }

    /** POST /api/auth/password/reset  { identifier, new_password, code? , id_token? } */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'min:8', 'max:255'],
            'code' => ['nullable', 'string'],
            'id_token' => ['nullable', 'string'],
        ]);

        $this->assertNotThrottled('api-auth-reset|'.Str::lower($data['identifier']).'|'.$request->ip(), 5);
        RateLimiter::hit('api-auth-reset|'.Str::lower($data['identifier']).'|'.$request->ip(), 60);

        if (CustomerAccountResolver::isEmail($data['identifier'])) {
            $email = Str::lower(trim($data['identifier']));
            $result = $this->otp->verify($email, OtpService::PURPOSE_PASSWORD_RESET, (string) ($data['code'] ?? ''));
            if (! $result['success']) {
                return response()->json(['message' => $this->otpFailureMessage($result['reason'])], $this->otpFailureStatus($result['reason']));
            }
            $user = $this->accounts->findByEmail($email);
        } else {
            if (blank($data['id_token'] ?? null)) {
                return response()->json(['message' => 'A verified mobile number is required.'], 422);
            }
            $identity = $this->verifyOrFail($data['id_token']);
            if (! $identity->isPhoneProvider() || PhoneNumber::national($data['identifier']) !== PhoneNumber::national((string) $identity->phoneNumber)) {
                return response()->json(['message' => 'The verified number does not match this account.'], 422);
            }
            $user = $this->accounts->findByPhone($data['identifier']);
        }

        if (! $user) {
            // Same wording either branch — do not confirm/deny existence.
            return response()->json(['message' => 'Password reset could not be completed.'], 422);
        }

        $this->accounts->setPassword($user, $data['new_password']);
        $user->tokens()->delete();

        return response()->json(['message' => 'Password updated. Please sign in with your new password.']);
    }

    // ═══════════════ Demoted OTP endpoints (verification only) ════════════

    /**
     * POST /api/auth/otp/request  { identifier, purpose }
     * purpose ∈ { email_verify, password_reset }. Email channel only —
     * phone codes now come from Firebase. NEVER issues a login token.
     */
    public function requestOtp(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required_without:phone', 'nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string'], // legacy field, no longer honoured for login
            'purpose' => ['required', 'string', 'in:'.OtpService::PURPOSE_EMAIL_VERIFY.','.OtpService::PURPOSE_PASSWORD_RESET],
        ]);

        if (blank($data['identifier'] ?? null)) {
            return response()->json([
                'message' => 'OTP login has been replaced. Use POST /api/auth/firebase for mobile sign-in, or pass an email `identifier` with a verification `purpose`.',
            ], 422);
        }

        $email = Str::lower(trim($data['identifier']));
        $this->assertNotThrottled('api-otp-request|'.$email.'|'.$request->ip(), 5);
        RateLimiter::hit('api-otp-request|'.$email.'|'.$request->ip(), 60);

        try {
            $this->otp->generate($email, $data['purpose'], $request->ip(), $request->header('X-Device-Id'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        return response()->json(['message' => 'If that email can receive a code, one has been sent.']);
    }

    /**
     * POST /api/auth/otp/verify  { identifier, purpose, code }
     * Returns only { verified: true } — no token, ever.
     */
    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required_without:phone', 'nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string'],
            'purpose' => ['required', 'string', 'in:'.OtpService::PURPOSE_EMAIL_VERIFY.','.OtpService::PURPOSE_PASSWORD_RESET],
            'code' => ['required', 'string'],
        ]);

        if (blank($data['identifier'] ?? null)) {
            return response()->json(['message' => 'OTP login has been replaced. Use POST /api/auth/firebase for mobile sign-in.'], 422);
        }

        $email = Str::lower(trim($data['identifier']));
        $this->assertNotThrottled('api-otp-verify|'.$email.'|'.$request->ip(), 10);
        RateLimiter::hit('api-otp-verify|'.$email.'|'.$request->ip(), 60);

        $result = $this->otp->verify($email, $data['purpose'], $data['code']);

        if (! $result['success']) {
            return response()->json(['message' => $this->otpFailureMessage($result['reason'])], $this->otpFailureStatus($result['reason']));
        }

        return response()->json(['verified' => true]);
    }

    // ═══════════════════════════ Session / device ════════════════════════

    /** POST /api/auth/logout */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** POST /api/auth/device */
    public function registerDevice(Request $request)
    {
        $validated = $request->validate(['fcm_token' => ['required', 'string', 'max:255']]);
        $request->user()->update(['fcm_token' => $validated['fcm_token']]);

        return response()->json(['message' => 'Device registered.']);
    }

    // ═══════════════════════════ Internals ═══════════════════════════════

    private function verifyOrFail(string $idToken): FirebaseIdentity
    {
        try {
            return $this->firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            throw ValidationException::withMessages(['id_token' => 'Could not verify that sign-in. Please try again.']);
        }
    }

    private function handleGoogleFirstLeg(FirebaseIdentity $google, string $actorType, Request $request)
    {
        $existing = $google->email ? $this->accounts->findByEmail($google->email) : null;
        $linked = $this->accounts->findByFirebaseUid($google->uid);

        if ($linked) {
            if (! $this->actorMatches($linked, $actorType)) {
                return response()->json(['message' => $this->noProfileMessage($actorType)], 404);
            }

            return $this->tokenResponse($linked, $actorType, $request);
        }

        if ($existing) {
            // Email matches an account that is NOT linked to Google. Do not
            // auto-link — require a Firebase phone verification for THAT
            // account's number as proof of ownership.
            return response()->json([
                'message' => 'An account already uses this email. Verify its mobile number to link Google sign-in.',
                'needs_phone_link' => true,
            ], 409);
        }

        // Brand-new Google identity — a mobile number is still mandatory.
        return response()->json([
            'message' => 'Verify a mobile number to finish creating your account.',
            'needs_phone' => true,
        ], 200);
    }

    private function linkOrCreateGoogleAccount(FirebaseIdentity $google, FirebaseIdentity $phone, ?string $password, string $actorType, Request $request)
    {
        $existing = $google->email ? $this->accounts->findByEmail($google->email) : null;

        try {
            if ($existing) {
                if (! $this->accounts->phoneMatches($existing, (string) $phone->phoneNumber)) {
                    return response()->json(['message' => 'The verified number does not match the account for this email.'], 422);
                }
                $this->accounts->linkFirebaseIdentity($existing, $google);
                $this->accounts->markPhoneVerified($existing);
                $user = $existing;
            } else {
                $user = $this->accounts->createFromGoogle($google, $phone, $password);
            }
        } catch (AccountAlreadyExistsException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (FirebaseAuthException $e) {
            report($e);

            return response()->json(['message' => 'Could not complete Google sign-in.'], 422);
        }

        return $this->tokenResponse($user, $actorType, $request);
    }

    private function registerCustomer(FirebaseIdentity $phone, string $name, string $password, ?string $verifiedEmail, Request $request, int $status = 200)
    {
        try {
            ['user' => $user] = $this->accounts->completeSignup($phone, $password, $name, $verifiedEmail);
        } catch (AccountAlreadyExistsException $e) {
            return response()->json(['message' => $e->getMessage().' Please sign in instead.'], 409);
        } catch (FirebaseAuthException $e) {
            report($e);
            throw ValidationException::withMessages(['id_token' => 'Could not verify that sign-in. Please try again.']);
        }

        return $this->tokenResponse($user, 'customer', $request, $status);
    }

    private function tokenResponse(User $user, string $actorType, Request $request, int $status = 200)
    {
        $deviceId = $request->header('X-Device-Id') ?: Str::random(12);
        $token = $user->createToken("web-auth:{$deviceId}")->plainTextToken;

        return response()->json([
            'token' => $token,
            'actor_type' => $actorType,
            'user' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'email' => $user->email, 'role' => $user->role],
        ], $status);
    }

    /** Does this user satisfy the profile requirement for the declared actor type? */
    private function actorMatches(User $user, string $actorType): bool
    {
        return match ($actorType) {
            'provider' => $user->providerProfile !== null,
            'field_worker' => $user->fieldWorkerProfile !== null,
            default => true, // customer: any user row
        };
    }

    private function noProfileMessage(string $actorType): string
    {
        return 'No '.str_replace('_', ' ', $actorType).' account found for this identity. Contact your administrator.';
    }

    private function emailWasVerified(string $email): bool
    {
        return \App\Models\Otp::where('identifier', Str::lower(trim($email)))
            ->where('purpose', OtpService::PURPOSE_EMAIL_VERIFY)
            ->where('status', \App\Models\Otp::STATUS_VERIFIED)
            ->where('verified_at', '>=', now()->subMinutes(30))
            ->exists();
    }

    private function assertNotThrottled(string $key, int $max): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                'identifier' => "Too many attempts. Try again in ".RateLimiter::availableIn($key)." seconds.",
            ])->status(429);
        }
    }

    private function otpFailureMessage(?string $reason): string
    {
        return match ($reason) {
            'locked' => 'Too many incorrect attempts. Request a new code.',
            'expired' => 'This code has expired. Request a new one.',
            'not_found' => 'No pending verification. Request a code first.',
            default => 'Incorrect code.',
        };
    }

    private function otpFailureStatus(?string $reason): int
    {
        return match ($reason) {
            'locked' => 429,
            'expired', 'not_found' => 410,
            default => 422,
        };
    }
}
