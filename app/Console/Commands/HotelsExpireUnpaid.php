<?php

namespace App\Console\Commands;

use App\Services\Hotels\HotelBookingService;
use Illuminate\Console\Command;

/**
 * Hotels H2b — no payment, no booking: online hotel bookings not paid within Hotel Settings' unpaid hold are cancelled
 * and their rooms freed. A payment captured later is refunded in full by HotelBookingService::markPaid().
 */
class HotelsExpireUnpaid extends Command
{
    protected $signature = 'hotels:expire-unpaid';

    protected $description = 'Cancels online hotel bookings not paid within hotel.unpaid_hold_minutes and frees their rooms';

    public function handle(HotelBookingService $bookings): int
    {
        $this->info('Expired '.$bookings->expireUnpaid().' unpaid hotel booking(s).');

        return self::SUCCESS;
    }
}
