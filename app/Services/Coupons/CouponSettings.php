<?php

namespace App\Services\Coupons;

use App\Models\Setting;

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

    /** Coupons can be offered only when switched on AND a hold length has been configured. */
    public static function available(): bool
    {
        return self::enabled() && self::unpaidHoldMinutes() !== null;
    }
}
