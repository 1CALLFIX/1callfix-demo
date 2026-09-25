<?php

namespace Tests\Feature\Support;

use App\Models\Setting;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — `payment.wallet_enabled` is null = OFF
 * since Stage 2 (it used to fall back to '1' in code). Tests written
 * against the old default opt back in explicitly, through the real settings
 * cascade, via Laravel's setUp{TraitName} hook (runs after RefreshDatabase).
 */
trait WithLegacyWalletPayments
{
    protected function setUpWithLegacyWalletPayments(): void
    {
        Setting::set('payment.wallet_enabled', '1');
    }
}
