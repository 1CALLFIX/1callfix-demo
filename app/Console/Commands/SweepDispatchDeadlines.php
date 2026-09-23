<?php

namespace App\Console\Commands;

use App\Services\DispatchDeadlineSweepService;
use Illuminate\Console\Command;

/**
 * REF 1CF-IMPLEMENT-20260922-L01 — thin command, same shape as
 * SendDailyDigest: delegates entirely to DispatchDeadlineSweepService so
 * the logic is unit-testable without an artisan process. Registered in
 * routes/console.php with ->everyMinute()->withoutOverlapping() — minute
 * granularity because the service itself decides per-booking whether that
 * booking's own T+5/T+30 has actually arrived (same DailyDigestDispatchService::
 * sendIfDue() idiom), not because the scheduler needs to be that precise.
 */
class SweepDispatchDeadlines extends Command
{
    protected $signature = 'dispatch:sweep-deadlines';

    protected $description = 'Escalates (T+5) and auto-cancels (T+30) Service Bookings still searching_provider with no assigned provider';

    public function handle(DispatchDeadlineSweepService $service): int
    {
        $result = $service->sweep();

        $this->info("Dispatch deadline sweep: {$result['escalated']} escalated, {$result['cancelled']} auto-cancelled.");

        return self::SUCCESS;
    }
}
