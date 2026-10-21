<?php

namespace App\Console\Commands;

use App\Services\ChargeWaiverService;
use Illuminate\Console\Command;

class EscalateChargeWaivers extends Command
{
    protected $signature = 'waivers:escalate';

    protected $description = 'Escalate charge waivers waiting for approval longer than waiver.charge.escalate_after_hours';

    public function handle(ChargeWaiverService $service): int
    {
        $this->info('Escalated: '.$service->escalateOverdue());

        return self::SUCCESS;
    }
}
