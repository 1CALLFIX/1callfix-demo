<?php

namespace App\Exceptions;

/**
 * Thrown by a PushAdapter implementation (see FirebaseFcmPushAdapter) when
 * the PROVIDER itself reports the device token as invalid/unregistered/
 * expired -- as opposed to a transient send failure. PushChannel catches
 * this specifically to clear the owning User's stale fcm_token (BD-8 Phase
 * 8 requirement: "handle invalid Firebase/FCM device tokens safely").
 *
 * LogPushAdapter never throws this -- the default/current behavior (no
 * cleanup happens today) is unchanged unless a real push adapter is bound.
 */
class InvalidPushTokenException extends \RuntimeException
{
    /**
     * The raw token the provider rejected, kept as a real (non-serialized-
     * to-message) property so PushChannel can compare it directly against
     * a User's CURRENT fcm_token before clearing it -- guards against
     * clearing a newer token in the rare case the user re-registered a
     * device between this send() call being made and this exception being
     * caught. Never included in getMessage() (see below) -- only ever
     * read in-process by PushChannel, never logged/serialized itself.
     */
    public readonly string $token;

    public function __construct(string $token)
    {
        $this->token = $token;

        // Never include the raw token verbatim in a message that might be
        // logged -- a device push token is a sensitive-ish credential-like
        // value (not a secret, but not something to echo around either).
        $masked = strlen($token) > 8 ? substr($token, 0, 4).'...'.substr($token, -4) : '***';
        parent::__construct("Push token rejected as invalid/unregistered by provider: {$masked}");
    }
}
