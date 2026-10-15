<?php

namespace App\Services\Coupons;

use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\BookingBundle;
use Illuminate\Support\Facades\DB;

/**
 * Payment gate: pay before dispatch. Coupon (benefit) bookings and — since the
 * 2026-10-15 audit (L4) — every online-paid instant booking are held at
 * creation by CreateBookingAction / CreateBookingBundleAction, and
 * RazorpayWebhookHandler releases them here once the capture lands. Before
 * L4 an online booking went to providers unpaid and could be worked and
 * completed without the customer ever paying.
 *
 * Scheduled bookings keep using ScheduledDispatchService::releaseIfEligible
 * (already payment-gated). Idempotent: only a still-`pending`, paid, never
 * dispatched coupon booking is released, once.
 */
class CouponDispatchGate
{
    public function releaseIfEligible(Booking $booking): void
    {
        if ($booking->scheduled_at !== null || ($booking->coupon_id === null && $booking->payment_method !== 'online')) {
            return;
        }

        $this->dispatchOnce($booking);
    }

    /** A coupon bundle's children, once the one aggregate payment is captured (same dispatch the bundle path always used). */
    public function releaseBundle(BookingBundle $bundle): void
    {
        if ($bundle->coupon_id === null && $bundle->payment_method !== 'online') {
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
