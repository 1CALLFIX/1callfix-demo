<?php

namespace Tests\Feature\Support;

use App\Models\Setting;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — the values the pre-Stage-2 code fell back
 * to in code, written explicitly through the real settings cascade for tests
 * that assumed them. Reference only — never a production default.
 */
final class EarningsSettingsFixtureValues
{
    public static function walletTopUp(): void
    {
        self::setAll([
            'wallet.topup_enabled' => '1',
            'payment.online_enabled' => '1',
            'wallet.customer_min_topup' => '100',
            'wallet.customer_max_topup' => '10000',
            'wallet.customer_max_balance' => '50000',
            'wallet.customer_daily_topup_limit' => '20000',
            'wallet.customer_monthly_topup_limit' => '100000',
        ]);
    }

    public static function loyalty(): void
    {
        self::setAll([
            'loyalty.customer_enabled' => '1',
            'loyalty.redeem_enabled' => '1',
            'loyalty.customer_points_per_currency_unit' => '0.01',
            'loyalty.points_per_rupee_redemption' => '10',
            'loyalty.min_redemption_points' => '100',
            'loyalty.points_expiry_days' => '365',
        ]);
    }

    public static function referral(?string $maxPerCustomer = '1000'): void
    {
        self::setAll([
            'referral.enabled' => '1',
            'referral.reward_type' => 'wallet',
            'referral.reward_amount' => '50',
            'referral.reward_points' => '100',
            'referral.max_per_customer' => $maxPerCustomer,
        ]);
    }

    private static function setAll(array $values): void
    {
        foreach ($values as $key => $value) {
            $value === null ? Setting::clear($key, 'global', null) : Setting::set($key, $value);
        }
    }
}
