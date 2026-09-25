<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * REF 1CF-PROMPT-20260925-EARN4 (R5, Migration 4) — keeps each user's most
 * recent login IP / device on the row, so a referral hold can compare a new
 * referee with a referrer who signed up before signup fingerprints existed.
 *
 * Called from the web `Login` event (every Auth::guard('web')->login()) and
 * from AuthController::tokenResponse() (every API token). A web login has no
 * device id, so it never overwrites the last one the app reported. A direct
 * query update: no model events, no observers, no audit noise.
 */
class LoginFingerprint
{
    public static function record(User $user, ?string $ip, ?string $deviceId): void
    {
        $values = ['last_login_ip' => $ip, 'last_login_at' => now()];
        if ($deviceId !== null) {
            $values['last_login_device_id'] = $deviceId;
        }

        User::query()->whereKey($user->getKey())->toBase()->update($values);
    }
}
