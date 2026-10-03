<?php

namespace App\Console\Commands;

use App\Services\Payments\MismatchRefundService;
use Illuminate\Console\Command;

/**
 * MANUAL MONEY ACTIONS — escalation step for the mismatch-refund queue.
 * Thin command: MismatchRefundService::escalateOverdue() holds the logic.
 * A no-op while refund.mismatch.escalate_after_hours is unset.
 */
class EscalateMismatchRefunds extends Command
{
    protected $signature = 'refunds:escalate-mismatch';

    protected $description = 'Alerts the next approval level about mismatch refunds that have waited past refund.mismatch.escalate_after_hours';

    public function handle(MismatchRefundService $service): int
    {
        $raised = $service->escalateOverdue();

        $this->info("Mismatch refunds: {$raised} escalation alert(s) raised.");

        return self::SUCCESS;
    }
}
