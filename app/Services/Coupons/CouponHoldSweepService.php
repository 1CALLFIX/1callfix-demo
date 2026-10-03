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

    /** @return int how many unpaid coupon bookings were cancelled */
    public function sweep(): int
    {
        $minutes = CouponSettings::unpaidHoldMinutes();
        if ($minutes === null) {
            return 0;
        }

        $cutoff = now()->subMinutes($minutes);

        $ids = Booking::query()
            ->whereNotNull('coupon_id')
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
                && $b->coupon_id !== null
                && $b->created_at <= $cutoff;
        });

        if (! $eligible) {
            return false;
        }

        try {
            $this->cancelAction->execute(
                $id,
                'Coupon booking not paid within the hold period — cancelled automatically; the coupon has been released.',
                cancelledByRole: 'system',
            );
        } catch (\Throwable $e) {
            Log::error("CouponHoldSweepService: could not cancel unpaid coupon booking [{$id}]: ".$e->getMessage());

            return false;
        }

        return true;
    }
}
