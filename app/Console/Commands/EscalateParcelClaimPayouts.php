<?php

namespace App\Console\Commands;

use App\Services\Parcel\ParcelProtectionClaimService;
use Illuminate\Console\Command;

/** MANUAL MONEY ACTIONS — escalation step for parcel protection payouts. A no-op while refund.parcel_claim.escalate_after_hours is unset. */
class EscalateParcelClaimPayouts extends Command
{
    protected $signature = 'refunds:escalate-parcel-claims';

    protected $description = 'Raises the escalation level of parcel protection payouts waiting past refund.parcel_claim.escalate_after_hours';

    public function handle(ParcelProtectionClaimService $service): int
    {
        $this->info("Parcel protection payouts: {$service->escalateOverdue()} escalation(s) recorded.");

        return self::SUCCESS;
    }
}
