<?php

namespace App\Services\Coupons;

use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\BookingBundle;
use Illuminate\Support\Facades\DB;

/**
 * Payment gate for coupon (benefit) bookings. An instant booking is normally
 * dispatched at creation, before payment; for a coupon booking that would let
 * a discounted job be worked unpaid, so CreateBookingAction /
 * CreateBookingBundleAction hold the dispatch and RazorpayWebhookHandler
 * releases it here once the capture lands.
 *
 * Scheduled bookings keep using ScheduledDispatchService::releaseIfEligible
 * (already payment-gated). Idempotent: only a still-`pending`, paid, never
 * dispatched coupon booking is released, once.
 */
class CouponDispatchGate
{
    public function releaseIfEligible(Booking $booking): void
    {
        if ($booking->coupon_id === null || $booking->scheduled_at !== null) {
            return;
        }

        $this->dispatchOnce($booking);
    }

    /** A coupon bundle's children, once the one aggregate payment is captured (same dispatch the bundle path always used). */
    public function releaseBundle(BookingBundle $bundle): void
    {
        if ($bundle->coupon_id === null) {
            return;
        }

        foreach ($bundle->children()->get() as $child) {
            $this->dispatchOnce($child);
        }
    }

    private function dispatchOnce(Booking $booking): void
    {
        $release = DB::transaction(function () use ($booking) {
            $locked = Booking::lockForUpdate()->find($booking->id);

            return $locked
                && $locked->status === 'pending'
                && $locked->payment_status === 'paid'
                && $locked->dispatch_deadline_at === null;
        });

        if ($release) {
            ServiceMatchingJob::dispatch($booking->id);
        }
    }
}
