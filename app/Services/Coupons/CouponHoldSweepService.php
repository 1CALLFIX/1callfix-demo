<?php

namespace App\Services\Coupons;

use App\Actions\AdminCancelBookingAction;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unpaid-hold sweep (decision Q11). A coupon booking is a benefit booking, so
 * it must be paid online at booking time. If the Razorpay payment has not been
 * captured within `coupons.unpaid_hold_minutes`, the booking is cancelled;
 * BookingObserver then releases the coupon usage (no work had started), so no
 * discounted booking survives unpaid and the reservation is given back.
 *
 * Since the 2026-10-15 audit (L4, pay before dispatch) the same hold also
 * covers every unpaid INSTANT online booking, which is now held (no offers)
 * until payment exactly like a coupon booking. Scheduled non-coupon bookings
 * keep their own ScheduledDispatchService payment gate and are not swept here.
 *
 * No-op while the hold length is unset (null = coupon bookings not allowed).
 * Wallet-paid coupon bookings are captured inside the creation transaction and
 * never reach this sweep. Idempotent and row-locked, like
 * DispatchDeadlineSweepService.
 */
class CouponHoldSweepService
{
    public function __construct(private AdminCancelBookingAction $cancelAction)
    {
    }

    /**
     * When an unpaid coupon booking runs out of time to pay (created_at + coupons.unpaid_hold_minutes), or null
     * when no hold applies to it. The same rule sweep() cancels on, exposed so the customer sees the real clock.
     */
    public static function expiresAt(Booking $booking): ?\Illuminate\Support\Carbon
    {
        $minutes = CouponSettings::unpaidHoldMinutes();

        if ($minutes === null
            || ! self::isHeldForPayment($booking)
            || $booking->status !== 'pending'
            || $booking->payment_status !== 'pending'
            || $booking->payment_method !== 'online') {
            return null;
        }

        return $booking->created_at?->copy()->addMinutes($minutes);
    }

    /** A booking this gate holds until paid: every coupon booking, and every instant (unscheduled) booking. */
    public static function isHeldForPayment(Booking $booking): bool
    {
        return $booking->coupon_id !== null || $booking->scheduled_at === null;
    }

    /** @return int how many unpaid held bookings were cancelled */
    public function sweep(): int
    {
        $minutes = CouponSettings::unpaidHoldMinutes();
        if ($minutes === null) {
            return 0;
        }

        $cutoff = now()->subMinutes($minutes);

        $ids = Booking::query()
            ->where(fn ($q) => $q->whereNotNull('coupon_id')->orWhereNull('scheduled_at'))
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('payment_method', 'online')
            ->where('created_at', '<=', $cutoff)
            ->pluck('id');

        $cancelled = 0;
        foreach ($ids as $id) {
            $cancelled += $this->cancelOne((int) $id, $cutoff) ? 1 : 0;
        }

        return $cancelled;
    }

    private function cancelOne(int $id, \DateTimeInterface $cutoff): bool
    {
        $eligible = DB::transaction(function () use ($id, $cutoff) {
            $b = Booking::lockForUpdate()->find($id);

            return $b
                && $b->status === 'pending'
                && $b->payment_status === 'pending'
                && $b->payment_method === 'online'
                && self::isHeldForPayment($b)
                && $b->created_at <= $cutoff;
        });

        if (! $eligible) {
            return false;
        }

        $hadCoupon = Booking::whereKey($id)->value('coupon_id') !== null;

        try {
            $this->cancelAction->execute(
                $id,
                $hadCoupon
                    ? 'Coupon booking not paid within the hold period — cancelled automatically; the coupon has been released.'
                    : 'Online booking not paid within the hold period — cancelled automatically before any professional was sent.',
                cancelledByRole: 'system',
            );
        } catch (\Throwable $e) {
            Log::error("CouponHoldSweepService: could not cancel unpaid coupon booking [{$id}]: ".$e->getMessage());

            return false;
        }

        return true;
    }
}
