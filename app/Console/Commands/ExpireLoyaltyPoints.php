<?php

namespace App\Console\Commands;

use App\Services\LoyaltyService;
use Illuminate\Console\Command;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D2. Materialises lapsed loyalty lots as
 * `expired` ledger rows (ref `loyalty-expire:{earnRowId}`, unique), oldest
 * unconsumed points first. Housekeeping only: the live balance
 * (LoyaltyService::balance()) is already correct before this runs — this
 * just makes SUM(points) agree with it and gives the customer a visible
 * "expired" line. Idempotent; safe to run any number of times.
 */
class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Writes FIFO expiry rows for lapsed loyalty points (idempotent via loyalty-expire:{earnRowId})';

    public function handle(LoyaltyService $loyalty): int
    {
        $result = $loyalty->expireLapsedPoints();

        $this->info("Wrote {$result['rows']} expiry row(s) totalling {$result['points']} point(s) across {$result['users']} user(s).");

        return self::SUCCESS;
    }
}
