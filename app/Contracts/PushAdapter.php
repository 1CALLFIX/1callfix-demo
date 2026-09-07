<?php

namespace App\Contracts;

/**
 * Swap the bound implementation (see AppServiceProvider::register()) for a
 * real provider (Firebase Cloud Messaging, etc.) when one is chosen —
 * nothing above this interface needs to change.
 */
interface PushAdapter
{
    /**
     * @param  array<string,string|null>  $data  Optional extra key/values
     *         carried alongside the notification. A `link` key, when present,
     *         is the URL the notification should open on click — the web SW
     *         (public/firebase-messaging-sw.js) reads it, and
     *         FirebaseFcmPushAdapter also maps it to FCM's
     *         `webpush.fcm_options.link`. Ignored by adapters that have no
     *         notion of click-through (LogPushAdapter just logs it).
     */
    public function send(string $token, string $title, string $body, array $data = []): bool;
}
