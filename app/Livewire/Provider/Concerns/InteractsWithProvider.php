<?php

namespace App\Livewire\Provider\Concerns;

use App\Models\Provider;

/**
 * PHASE PW1 — every provider-web component resolves "the current partner"
 * the same way. EnsureIsProvider has already guaranteed the row exists, so
 * firstOrFail() here is a belt-and-braces assertion, never the real guard.
 */
trait InteractsWithProvider
{
    protected function provider(): Provider
    {
        return auth()->user()->providerProfile()->with(['user:id,name,phone', 'zone:id,name'])->firstOrFail();
    }
}
