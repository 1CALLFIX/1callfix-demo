<?php

namespace App\Console\Commands;

use App\Services\Coupons\CouponHoldSweepService;
use Illuminate\Console\Command;

/** Thin command over CouponHoldSweepService, same shape as dispatch:sweep-deadlines. */
class SweepUnpaidCouponBookings extends Command
{
    protected $signature = 'coupons:sweep-unpaid';

    protected $description = 'Cancels coupon bookings whose online payment was not captured within coupons.unpaid_hold_minutes and releases their coupon';

    public function handle(CouponHoldSweepService $service): int
    {
        $this->info("Coupon unpaid-hold sweep: {$service->sweep()} cancelled.");

        return self::SUCCESS;
    }
}
