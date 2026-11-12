<?php

namespace App\Console\Commands;

use App\Services\Hotels\HotelStayService;
use Illuminate\Console\Command;

/** Hotels H3 — paid bookings a hotel did not accept within hotel.accept_window_minutes: cancelled, refunded in full. */
class HotelsExpireUnaccepted extends Command
{
    protected $signature = 'hotels:expire-unaccepted';

    protected $description = 'Cancels and fully refunds paid hotel bookings the hotel did not accept in time';

    public function handle(HotelStayService $stays): int
    {
        $this->info('Cancelled '.$stays->expireUnaccepted().' unaccepted hotel booking(s).');

        return self::SUCCESS;
    }
}
