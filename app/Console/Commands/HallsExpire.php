<?php

namespace App\Console\Commands;

use App\Services\Halls\HallBookingService;
use Illuminate\Console\Command;

/**
 * Function halls R2 — every minute: (1) no payment, no booking: online hall bookings not paid within Hall Settings'
 * unpaid hold free their slot (a payment captured later is refunded in full by HallBookingService::markPaid());
 * (2) paid bookings the owner did not accept within Hall Settings' answer time are declined and refunded in full.
 */
class HallsExpire extends Command
{
    protected $signature = 'halls:expire';

    protected $description = 'Frees unpaid hall bookings past the hold, and declines + refunds paid ones the owner did not answer in time';

    public function handle(HallBookingService $halls): int
    {
        $this->info('Expired '.$halls->expireUnpaid().' unpaid hall booking(s); declined '.$halls->declineUnanswered().' unanswered.');

        return self::SUCCESS;
    }
}
