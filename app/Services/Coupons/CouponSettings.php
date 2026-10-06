<?php

namespace App\Services\Coupons;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;

/**
 * Super Admin settings for the coupon engine. Null = not configured = fail
 * closed (no value is invented here; the owner enters them).
 */
class CouponSettings
{
    /** Kill switch. Anything other than '1' is OFF. */
    public static function enabled(): bool
    {
        return Setting::get('coupons.enabled') === '1';
    }

    /**
     * How long an unpaid online coupon booking may wait for payment before the
     * sweep cancels it. Null/invalid/<=0 => coupon bookings are not allowed.
     */
    public static function unpaidHoldMinutes(): ?int
    {
        $value = Setting::get('coupons.unpaid_hold_minutes');

        if ($value === null || $value === '' || ! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * The ONE write path for the global coupon settings (hardening §J). Super Admin only — the `super_admin`
     * role itself, never a grantable permission (coupons.approve does not reach this). Checked here so it holds
     * for any caller, not just the Livewire screen. Every changed key is audit-logged by SettingsAuditor.
     *
     * @return bool whether anything changed
     */
    public static function save(?User $actor, bool $enabled, ?string $holdMinutes): bool
    {
        abort_unless(SuperAdminGate::allows($actor), 403, 'Only a Super Admin can change global coupon settings.');

        $changed = SettingsAuditor::put($actor, 'coupons.enabled', $enabled ? '1' : '0');

        return SettingsAuditor::put($actor, 'coupons.unpaid_hold_minutes', $holdMinutes) || $changed;
    }

    /** The customer surfaces that can show a coupon field (the web wizard, cart, checkout and bundles, plus their API). */
    public const SURFACES = ['wizard', 'cart', 'checkout', 'bundles'];

    /** Owner's values (C3): attempts per window per customer / per IP. Used only while the setting is unset. */
    public const DEFAULT_ATTEMPTS_PER_CUSTOMER = 10;

    public const DEFAULT_ATTEMPTS_PER_IP = 30;

    public const DEFAULT_WINDOW_SECONDS = 60;

    /**
     * Per-surface switch behind the master `coupons.enabled`. Unset = on, so no surface is hidden by default;
     * only an explicit '0' turns one off. An unknown surface is never enabled.
     */
    public static function surfaceEnabled(string $surface): bool
    {
        return in_array($surface, self::SURFACES, true) && Setting::get("coupons.surface.{$surface}") !== '0';
    }

    /** A coupon field may be shown/used on this surface: engine available AND this surface on. */
    public static function entryAvailable(string $surface): bool
    {
        return self::available() && self::surfaceEnabled($surface);
    }

    public static function attemptsPerCustomer(): int
    {
        return self::positiveInt('coupons.rate_limit.customer_attempts', self::DEFAULT_ATTEMPTS_PER_CUSTOMER);
    }

    public static function attemptsPerIp(): int
    {
        return self::positiveInt('coupons.rate_limit.ip_attempts', self::DEFAULT_ATTEMPTS_PER_IP);
    }

    public static function attemptWindowSeconds(): int
    {
        return self::positiveInt('coupons.rate_limit.window_seconds', self::DEFAULT_WINDOW_SECONDS);
    }

    private static function positiveInt(string $key, int $default): int
    {
        $value = Setting::get($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * The ONE write path for the coupon-entry controls (C3 items 5 and 6): per-surface switches and the
     * attempt rate limit. Super Admin only, checked here so it holds for any caller. A null number clears the
     * setting back to its default. Every changed key is audit-logged by SettingsAuditor.
     *
     * @param  array<string, bool>  $surfaces  surface => on
     * @return bool whether anything changed
     */
    public static function saveEntryControls(?User $actor, array $surfaces, ?int $customerAttempts, ?int $ipAttempts, ?int $windowSeconds): bool
    {
        abort_unless(SuperAdminGate::allows($actor), 403, 'Only a Super Admin can change global coupon settings.');

        $changed = false;
        foreach ($surfaces as $surface => $on) {
            if (in_array($surface, self::SURFACES, true)) {
                $changed = SettingsAuditor::put($actor, "coupons.surface.{$surface}", $on ? '1' : '0') || $changed;
            }
        }

        $changed = SettingsAuditor::put($actor, 'coupons.rate_limit.customer_attempts', $customerAttempts) || $changed;
        $changed = SettingsAuditor::put($actor, 'coupons.rate_limit.ip_attempts', $ipAttempts) || $changed;

        return SettingsAuditor::put($actor, 'coupons.rate_limit.window_seconds', $windowSeconds) || $changed;
    }

    /** Coupons can be offered only when switched on AND a hold length has been configured. */
    public static function available(): bool
    {
        return self::enabled() && self::unpaidHoldMinutes() !== null;
    }
}
