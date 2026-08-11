<?php

namespace App\Contracts;

/**
 * Swap the bound implementation (see AppServiceProvider::register()) for a
 * real provider (Firebase Cloud Messaging, etc.) when one is chosen —
 * nothing above this interface needs to change.
 */
interface PushAdapter
{
    public function send(string $token, string $title, string $body): bool;
}
