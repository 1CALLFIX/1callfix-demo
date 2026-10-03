<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\Coupons\CouponService;
use App\Services\OrderCodeService;

class BookingObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    /**
     * Coupon usage lifecycle (design §7). Every cancel / completion path
     * saves the model, so one observer covers admin, customer, provider,
     * sweeps and bundle children without touching each Action. No-op for a
     * booking without a coupon.
     */
    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        if ($booking->status === 'completed') {
            app(CouponService::class)->onBookingCompleted($booking);
        } elseif ($booking->status === 'cancelled') {
            app(CouponService::class)->onBookingCancelled($booking, $booking->getOriginal('status'));
        }
    }

    /**
     * Auto-generate booking.code before the record is saved for the first time.
     * Never overwrites a code if one is already set (e.g. in tests/seeders).
     */
    public function creating(Booking $booking): void
    {
        if (empty($booking->code)) {
            $franchise = $booking->franchise ?? \App\Models\Franchise::findOrFail($booking->franchise_id);
            $booking->code = $this->orderCodeService->generate($franchise);
        }
    }
}
