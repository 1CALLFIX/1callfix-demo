<?php

namespace App\Console\Commands;

use App\Services\Payments\UnpaidAttemptService;
use Illuminate\Console\Command;

/** NO PAYMENT, NO BOOKING (owner 2026-11-07): ends unpaid online service-booking attempts after the set minutes. */
class ExpireUnpaidAttempts extends Command
{
    protected $signature = 'bookings:expire-unpaid-attempts';

    protected $description = 'End online service-booking payment attempts not paid within payments.unpaid_attempt_minutes (default 15); no booking is made';

    public function handle(UnpaidAttemptService $attempts): int
    {
        $this->info('Unpaid payment attempts ended: '.$attempts->expire());

        return self::SUCCESS;
    }
}
