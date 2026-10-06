<?php

namespace App\Services\Payments;

use App\Exceptions\OnlinePaymentRequiredException;
use Illuminate\Database\Eloquent\Model;

/**
 * THUMB RULE (CLAUDE.md) — ONLINE PAYMENT ONLY FOR ALL BENEFITS. The ONE place that decides whether a
 * payment method may carry a benefit; every benefit path (coupon engine, Booking / BookingBundle model
 * guards, any future benefit type) calls this instead of keeping its own list or wording.
 *
 * Online = Razorpay ('online'), 'wallet', or wallet + Razorpay. Cash is never online, and a split with ANY
 * cash leg is not online either ("1 rupee online + the rest cash" never unlocks a benefit).
 *
 * Judged on the ORIGINAL booking payment: a later separate charge (approved extra work) is a different
 * charge and never reaches these methods.
 */
final class OnlinePaymentGuard
{
    public const MESSAGE = 'Offers apply on online payment only.';

    public const ONLINE_METHODS = ['online', 'wallet'];

    public static function isOnline(?string $method): bool
    {
        return in_array($method, self::ONLINE_METHODS, true);
    }

    /** @throws OnlinePaymentRequiredException */
    public static function assertOnline(?string $method): void
    {
        if (! self::isOnline($method)) {
            throw new OnlinePaymentRequiredException(self::MESSAGE);
        }
    }

    /**
     * A (future) split payment: every leg must be online/wallet. No legs = no payment = not eligible.
     *
     * @param  array<int, array{method: ?string, amount?: float|int|string}>  $legs
     */
    public static function legsAreOnline(array $legs): bool
    {
        if ($legs === []) {
            return false;
        }

        foreach ($legs as $leg) {
            if (! self::isOnline($leg['method'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{method: ?string, amount?: float|int|string}>  $legs
     *
     * @throws OnlinePaymentRequiredException
     */
    public static function assertLegsOnline(array $legs): void
    {
        if (! self::legsAreOnline($legs)) {
            throw new OnlinePaymentRequiredException(self::MESSAGE);
        }
    }

    /**
     * Model-level guard for `updating`: a record that carries a benefit can never change its payment method
     * (web, API, provider app, admin alike) — in particular never to cash.
     *
     * @throws OnlinePaymentRequiredException
     */
    public static function assertMethodUnchanged(Model $model, bool $hasBenefit): void
    {
        if ($hasBenefit && $model->isDirty('payment_method')) {
            throw new OnlinePaymentRequiredException(self::MESSAGE);
        }
    }
}
