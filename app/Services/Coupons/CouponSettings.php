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

    /** Coupons can be offered only when switched on AND a hold length has been configured. */
    public static function available(): bool
    {
        return self::enabled() && self::unpaidHoldMinutes() !== null;
    }
}
