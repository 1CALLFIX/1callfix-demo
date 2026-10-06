<?php

namespace App\Services\Coupons;

use App\Exceptions\CouponEntryBlockedException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one door every customer coupon attempt goes through (validate API, booking / bundle APIs, wizard, cart,
 * checkout): the surface must be switched on (CouponSettings::entryAvailable) and the customer / IP must be
 * inside the Super Admin rate limit (attempts per window, per customer and per IP). Nothing is hardcoded here.
 */
class CouponEntryGate
{
    /** @throws CouponEntryBlockedException */
    public static function attempt(User $customer, string $surface, ?string $ip): void
    {
        if (! CouponSettings::entryAvailable($surface)) {
            throw CouponEntryBlockedException::unavailable();
        }

        $window = CouponSettings::attemptWindowSeconds();
        $buckets = [
            'coupon:customer:'.$customer->id => CouponSettings::attemptsPerCustomer(),
            'coupon:ip:'.($ip ?? 'unknown') => CouponSettings::attemptsPerIp(),
        ];

        foreach ($buckets as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw CouponEntryBlockedException::rateLimited();
            }
        }

        foreach (array_keys($buckets) as $key) {
            RateLimiter::hit($key, $window);
        }
    }
}
