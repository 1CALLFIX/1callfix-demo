<?php

namespace App\Console\Commands;

use App\Services\Cancellation\CancellationSweepService;
use Illuminate\Console\Command;

/** REF 1CF-CANCEL-POLICY-001 — hourly spares-delay notices, extra-work timeout, unpaid-charge flagging, payout retries. */
class CancellationSweep extends Command
{
    protected $signature = 'cancellation:sweep';

    protected $description = 'Spares-delay notices, extra-work 72h timeout, unpaid cancellation charges, provider payout retries';

    public function handle(CancellationSweepService $sweep): int
    {
        foreach ($sweep->run() as $what => $count) {
            $this->line("{$what}: {$count}");
        }

        return self::SUCCESS;
    }
}
