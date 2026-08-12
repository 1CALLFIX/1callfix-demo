<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Login authentication for Customer/Partner/Worker — the SAME shared
 * OtpService/Sanctum foundation for all three (AUTHENTICATION_ARCHITECTURE.md),
 * distinguished only by the `actor_type` the caller declares itself as —
 * same "acting_as" convention PlanController already established in this
 * codebase, not a new one. No RBAC/hasPermission() involved (role_assignments
 * is admin-panel-only, matching WorkerJobController/PartnerWorkerController's
 * existing docblock reasoning).
 */
class AuthController extends Controller
{
    private const ACTOR_TYPES = ['customer', 'provider', 'field_worker'];

    /**
     * POST /api/auth/otp/request
     * Deliberately returns the SAME generic response regardless of whether
     * a provider/field_worker phone actually has an account — an
     * enumeration-safe response (Part 21). For actor_type=customer, an
     * account doesn't need to pre-exist (self-registration on first
     * verified login is the deliberate design — see AUTHENTICATION_ARCHITECTURE.md),
     * so this always actually sends. For provider/field_worker, this
     * silently no-ops (no OTP sent, no SMS cost, no abuse vector) when no
     * matching account exists, while still returning the identical
     * response either way.
     */
    public function requestOtp(Request $request, OtpService $otpService)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'actor_type' => ['required', 'string', 'in:'.implode(',', self::ACTOR_TYPES)],
        ]);

        $shouldSend = $validated['actor_type'] === 'customer' || $this->accountExists($validated['phone'], $validated['actor_type']);

        if ($shouldSend) {
            try {
                $otpService->generate($validated['phone'], 'login', $request->ip(), $request->header('X-Device-Id'));
            } catch (\RuntimeException $e) {
                // Resend-cooldown message is safe to surface as-is — it
                // doesn't leak account existence, just timing of the
                // caller's OWN immediately-prior request.
                return response()->json(['message' => $e->getMessage()], 429);
            }
        }

        return response()->json(['message' => 'If this phone number is registered, a verification code has been sent.']);
    }

    /**
     * POST /api/auth/otp/verify
     * Customer: first-or-create by phone (self-registration on first
     * verified login — no KYC gate exists for customers anywhere in this
     * codebase today, matching Bookings\Index::createCustomer()'s own
     * no-gate admin-created-customer flow). Provider/field_worker: MUST
     * already exist — these actor types are KYC-gated onboarding
     * (ReviewProviderKycAction/ReviewFieldWorkerKycAction), and OTP login
     * verifying phone ownership must never be able to bypass that; a
     * correct code for a phone with no provider/worker profile is
     * rejected with a clear, actionable 404 (safe to be specific here —
     * the caller already proved phone ownership by this point).
     */
    public function verifyOtp(Request $request, OtpService $otpService)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'actor_type' => ['required', 'string', 'in:'.implode(',', self::ACTOR_TYPES)],
            'code' => ['required', 'string'],
        ]);

        $result = $otpService->verify($validated['phone'], 'login', $validated['code']);

        if (! $result['success']) {
            $status = match ($result['reason']) {
                'locked' => 429,
                'expired', 'not_found' => 410,
                default => 422,
            };
            $message = match ($result['reason']) {
                'locked' => 'Too many incorrect attempts. Request a new code.',
                'expired' => 'This code has expired. Request a new one.',
                'not_found' => 'No pending verification for this phone. Request a code first.',
                default => 'Incorrect code.',
            };

            return response()->json(['message' => $message], $status);
        }

        $user = match ($validated['actor_type']) {
            'customer' => $this->resolveCustomer($validated['phone']),
            default => $this->resolveExistingActor($validated['phone'], $validated['actor_type']),
        };

        if (! $user) {
            return response()->json(['message' => 'No '.str_replace('_', ' ', $validated['actor_type']).' account found for this phone. Contact your administrator.'], 404);
        }

        $deviceId = $request->header('X-Device-Id') ?: Str::random(12);
        $token = $user->createToken("otp-login:{$deviceId}")->plainTextToken;

        return response()->json([
            'token' => $token,
            'actor_type' => $validated['actor_type'],
            'user' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'role' => $user->role],
        ]);
    }

    /** POST /api/auth/logout */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * POST /api/auth/device
     * Registers/updates this user's push token. Single-device only —
     * users.fcm_token is one nullable column, not a devices table (see
     * AUTH_FORENSIC_DISCOVERY.md) — a second device registering overwrites
     * the first. Documented limitation, not silently hidden.
     */
    public function registerDevice(Request $request)
    {
        $validated = $request->validate(['fcm_token' => ['required', 'string', 'max:255']]);

        $request->user()->update(['fcm_token' => $validated['fcm_token']]);

        return response()->json(['message' => 'Device registered.']);
    }

    private function accountExists(string $phone, string $actorType): bool
    {
        return $this->resolveExistingActor($phone, $actorType) !== null;
    }

    private function resolveExistingActor(string $phone, string $actorType): ?User
    {
        $user = User::where('phone', $phone)->first();
        if (! $user) {
            return null;
        }

        $hasProfile = match ($actorType) {
            'provider' => $user->providerProfile !== null,
            'field_worker' => $user->fieldWorkerProfile !== null,
            default => false,
        };

        return $hasProfile ? $user : null;
    }

    private function resolveCustomer(string $phone): User
    {
        $user = User::where('phone', $phone)->first();
        if ($user) {
            return $user;
        }

        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Customer',
            'phone' => $phone,
            'role' => 'customer',
            'status' => 'active',
            'preferred_language' => 'en',
        ]);
    }
}
