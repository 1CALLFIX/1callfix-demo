<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Customer account resolution for a phone number that has just proved
 * ownership via a verified login OTP — first-or-create by phone
 * (self-registration on first verified login is the deliberate design, see
 * AUTHENTICATION_ARCHITECTURE.md; no KYC gate exists for customers anywhere
 * in this codebase, matching Bookings\Index::createCustomer()'s own
 * no-gate admin-created-customer flow).
 *
 * Extracted verbatim from AuthController::resolveCustomer(), which was
 * private and therefore unreachable from anywhere else. Phase B of the
 * customer web app needs the SAME rule for its session-based
 * (Auth::guard('web')) login as the API's token-based one — and the one
 * thing that must not happen is a second, drifting copy of "what an
 * account for a verified phone looks like". AuthController now delegates
 * here, so both callers share one implementation and the existing
 * tests/Feature/Auth/AuthOtpTest.php coverage continues to prove it.
 *
 * Deliberately NOT responsible for verifying the OTP — that stays entirely
 * inside OtpService. This class is only reached once a caller already holds
 * a successful verify() result, and it must never be called before that.
 */
class CustomerAccountResolver
{
    public function resolve(string $phone): User
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
