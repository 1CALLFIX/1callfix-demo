<?php

namespace App\Console\Commands;

use App\Services\BookingDisputeService;
use Illuminate\Console\Command;

/** MANUAL MONEY ACTIONS — escalation step for dispute refunds. A no-op while refund.dispute.escalate_after_hours is unset. */
class EscalateBookingDisputeRefunds extends Command
{
    protected $signature = 'refunds:escalate-disputes';

    protected $description = 'Raises the escalation level of dispute refunds waiting past refund.dispute.escalate_after_hours';

    public function handle(BookingDisputeService $service): int
    {
        $this->info("Dispute refunds: {$service->escalateOverdue()} escalation(s) recorded.");

        return self::SUCCESS;
    }
}
