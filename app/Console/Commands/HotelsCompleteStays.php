<?php

namespace App\Console\Commands;

use App\Services\Hotels\HotelStayService;
use Illuminate\Console\Command;

/** Hotels H3 — stays checked out longer than hotel.payout_hold_hours: completed, commission split, the hotel paid. */
class HotelsCompleteStays extends Command
{
    protected $signature = 'hotels:complete-stays';

    protected $description = 'Completes hotel stays past the payout hold and pays the hotel its share';

    public function handle(HotelStayService $stays): int
    {
        $this->info('Completed '.$stays->completeDue().' hotel stay(s).');

        return self::SUCCESS;
    }
}
