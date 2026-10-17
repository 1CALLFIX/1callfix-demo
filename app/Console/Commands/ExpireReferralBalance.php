<?php

namespace App\Console\Commands;

use App\Services\ReferralBalanceService;
use Illuminate\Console\Command;

/**
 * Owner decision 2026-10-17 — removes referral balance grants past their expiry date. Only grants given while
 * referral.balance_expiry_days was set carry an expiry date (blank = never expires). Safe to run repeatedly.
 */
class ExpireReferralBalance extends Command
{
    protected $signature = 'referrals:expire-balance';

    protected $description = 'Expires referral balance grants whose expiry date has passed (only grants given while referral.balance_expiry_days was set)';

    public function handle(ReferralBalanceService $balances): int
    {
        $count = $balances->expireDue();

        $this->info("Expired {$count} referral balance grant(s).");

        return self::SUCCESS;
    }
}
