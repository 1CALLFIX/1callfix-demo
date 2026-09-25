<?php

namespace Tests\Feature\Support;

use App\Models\Setting;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — since Stage 2, every Earnings key is
 * null = OFF with no code fallback. These helpers configure the SAME values
 * the old code fell back to, explicitly, through the real settings cascade
 * (see EarningsSettingsFixtureValues). Reference only.
 */
trait EarningsSettingsFixture
{
    protected function configureLegacyWalletTopUp(): void
    {
        EarningsSettingsFixtureValues::walletTopUp();
    }

    protected function configureLegacyLoyalty(): void
    {
        EarningsSettingsFixtureValues::loyalty();
    }

    protected function configureLegacyProviderLoyalty(): void
    {
        Setting::set('loyalty.provider_enabled', '1');
        Setting::set('loyalty.provider_points_per_completed_job', '5');
        Setting::set('loyalty.points_expiry_days', Setting::get('loyalty.points_expiry_days') ?? '365');
    }

    protected function configureLegacyReferral(?string $maxPerCustomer = '1000'): void
    {
        EarningsSettingsFixtureValues::referral($maxPerCustomer);
    }

    protected function configureWalletPayments(): void
    {
        Setting::set('payment.wallet_enabled', '1');
    }
}
