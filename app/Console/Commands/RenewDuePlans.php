<?php

namespace App\Console\Commands;

use App\Services\Plans\RenewalService;
use Illuminate\Console\Command;

class RenewDuePlans extends Command
{
    protected $signature = 'plans:renew-due';

    protected $description = 'Processes every subscription whose current period has ended: renews zero-price plans, moves paid plans to past_due/grace_period, applies rollover, applies queued upgrades/downgrades, and expires anything past its grace deadline.';

    public function handle(RenewalService $renewalService): int
    {
        $counts = $renewalService->processDueSubscriptions();

        if (empty($counts)) {
            $this->info('No subscriptions due.');

            return self::SUCCESS;
        }

        foreach ($counts as $outcome => $count) {
            $this->info("{$outcome}: {$count}");
        }

        return self::SUCCESS;
    }
}
