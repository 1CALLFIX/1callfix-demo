<?php

namespace App\Support;

use App\Models\Setting;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — the Rule of Law read side for every
 * Earnings key. Reads go through the ONE existing cascade (Setting::get());
 * there is no second settings system and no fallback: an unset key reads as
 * null, and null means OFF.
 *
 *   - switch keys are ON only when the stored value is exactly '1';
 *   - numeric keys return null when unset (or blank), else the number —
 *     an explicit '0' is an explicit zero, never confused with unset.
 *
 * KEYS lists every key the Earnings feature reads, which is what
 * `earnings:settings-report` prints and what Earnings Control shows.
 */
final class EarningsSettings
{
    /** Switch keys owned by Earnings Control. `global` = read without a scope. */
    public const SWITCHES = [
        'earnings.enabled' => ['label' => 'Customer Earnings section (master)', 'global' => false],
        'earnings.wallet_tab' => ['label' => 'Earnings → Wallet tab', 'global' => false],
        'earnings.loyalty_tab' => ['label' => 'Earnings → Loyalty tab', 'global' => false],
        'earnings.referral_tab' => ['label' => 'Earnings → Referrals tab', 'global' => false],
        'wallet.topup_enabled' => ['label' => 'Customer wallet top-up', 'global' => false],
        'loyalty.customer_enabled' => ['label' => 'Customer loyalty points earning', 'global' => false],
        'loyalty.provider_enabled' => ['label' => 'Provider loyalty points earning', 'global' => false],
        'loyalty.redeem_enabled' => ['label' => 'Loyalty points redemption (customers only)', 'global' => false],
        'referral.enabled' => ['label' => 'Referral rewards', 'global' => false],
    ];

    /** Numeric limits owned by Earnings Control. */
    public const LIMITS = [
        'referral.max_per_customer' => ['label' => 'Max rewarded referrals per customer', 'global' => false, 'integer' => true],
        'wallet.admin_adjustment_max' => ['label' => 'Max single admin wallet adjustment (₹)', 'global' => true, 'integer' => false],
        'loyalty.admin_adjustment_max' => ['label' => 'Max single admin points adjustment', 'global' => true, 'integer' => true],
        'earnings.flag_refund_above' => ['label' => 'Flag refunds above (₹)', 'global' => true, 'integer' => false],
        'earnings.flag_referrals_above' => ['label' => 'Flag referrers with more rewarded referrals than', 'global' => true, 'integer' => true],
    ];

    /**
     * Every key the Earnings feature reads (switches, limits, and the
     * pre-existing Wallet / Loyalty / Referral / Payment tab keys), with where
     * it is edited. Printed by earnings:settings-report.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        'earnings.enabled' => 'Earnings Control',
        'earnings.wallet_tab' => 'Earnings Control',
        'earnings.loyalty_tab' => 'Earnings Control',
        'earnings.referral_tab' => 'Earnings Control',
        'wallet.topup_enabled' => 'Earnings Control',
        'loyalty.customer_enabled' => 'Earnings Control',
        'loyalty.provider_enabled' => 'Earnings Control',
        'loyalty.redeem_enabled' => 'Earnings Control',
        'referral.enabled' => 'Earnings Control',
        'referral.max_per_customer' => 'Earnings Control',
        'wallet.admin_adjustment_max' => 'Earnings Control (global only)',
        'loyalty.admin_adjustment_max' => 'Earnings Control (global only)',
        'earnings.flag_refund_above' => 'Earnings Control (global only)',
        'earnings.flag_referrals_above' => 'Earnings Control (global only)',
        'wallet.customer_min_topup' => 'Settings → Wallet',
        'wallet.customer_max_topup' => 'Settings → Wallet',
        'wallet.customer_max_balance' => 'Settings → Wallet',
        'wallet.customer_daily_topup_limit' => 'Settings → Wallet',
        'wallet.customer_monthly_topup_limit' => 'Settings → Wallet',
        'payment.online_enabled' => 'Settings → Payment',
        'payment.wallet_enabled' => 'Settings → Payment',
        'loyalty.customer_points_per_currency_unit' => 'Settings → Loyalty / Referral',
        'loyalty.provider_points_per_completed_job' => 'Settings → Loyalty / Referral',
        'loyalty.points_per_rupee_redemption' => 'Settings → Loyalty / Referral',
        'loyalty.min_redemption_points' => 'Settings → Loyalty / Referral',
        'loyalty.points_expiry_days' => 'Settings → Loyalty / Referral',
        'referral.reward_type' => 'Settings → Loyalty / Referral',
        'referral.reward_amount' => 'Settings → Loyalty / Referral',
        'referral.reward_points' => 'Settings → Loyalty / Referral',
        'referral.pending_expiry_days' => 'Settings → Loyalty / Referral (global only)',
    ];

    public static function on(string $key, array $scope = []): bool
    {
        return (string) Setting::get($key, null, $scope) === '1';
    }

    public static function raw(string $key, array $scope = []): ?string
    {
        $value = Setting::get($key, null, $scope);

        return ($value === null || trim((string) $value) === '') ? null : (string) $value;
    }

    public static function number(string $key, array $scope = []): ?float
    {
        $value = self::raw($key, $scope);

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    public static function integer(string $key, array $scope = []): ?int
    {
        $value = self::number($key, $scope);

        return $value === null ? null : (int) $value;
    }

    /** ON / OFF / UNSET for the value stored at EXACTLY this scope (no cascade). */
    public static function statusAt(string $key, string $scopeType, ?int $scopeId): string
    {
        $row = Setting::where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('key', $key)->first();

        if (! $row || $row->value === null || trim((string) $row->value) === '') {
            return 'UNSET';
        }

        if (array_key_exists($key, self::SWITCHES)) {
            return $row->value === '1' ? 'ON' : 'OFF';
        }

        return (string) $row->value;
    }

    /** Is this switch, as the customer at $scope would resolve it, on — with the master switch on too. */
    public static function customerTabOn(string $tabKey, array $scope = []): bool
    {
        return self::on('earnings.enabled', $scope) && self::on($tabKey, $scope);
    }
}
