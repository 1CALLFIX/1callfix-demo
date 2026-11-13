<?php

namespace App\Console\Commands;

use App\Services\Halls\HallBookingService;
use Illuminate\Console\Command;

/**
 * Function halls R3 — every hour, nobody has to do it by hand:
 *  - balance reminders from 3 days before the due date (once a day per booking);
 *  - a balance still unpaid 2 days after the due date: the booking is cancelled under the normal cancellation rule;
 *  - bookings past the event + Hall Settings' payout hours: completed, the owner paid (after commission);
 *  - deposits past Hall Settings' return hours with no damage reported: back to the customer.
 */
class HallsAfterEvents extends Command
{
    protected $signature = 'halls:after-events';

    protected $description = 'Hall balance reminders, unpaid-balance cancellations, owner payouts after the event, and deposit returns';

    public function handle(HallBookingService $halls): int
    {
        $reminded = $halls->remindBalance();
        $cancelled = $halls->cancelUnpaidBalances();
        $done = $halls->afterEvents();
        $this->info("Reminded {$reminded}; cancelled {$cancelled} unpaid; completed {$done['completed']}; paid {$done['paid_cancelled']} cancelled; returned {$done['deposits']} deposit(s).");

        return self::SUCCESS;
    }
}
