<?php

namespace App\Console\Commands;

use App\Services\Halls\HallDamageClaimService;
use Illuminate\Console\Command;

/** MANUAL MONEY ACTIONS — escalation step for hall damage claims. A no-op while refund.hall_damage.escalate_after_hours is unset. */
class EscalateHallDamageClaims extends Command
{
    protected $signature = 'refunds:escalate-hall-damage';

    protected $description = 'Raises the escalation level of hall damage claims waiting past refund.hall_damage.escalate_after_hours';

    public function handle(HallDamageClaimService $service): int
    {
        $this->info("Hall damage claims: {$service->escalateOverdue()} escalation(s) recorded.");

        return self::SUCCESS;
    }
}
