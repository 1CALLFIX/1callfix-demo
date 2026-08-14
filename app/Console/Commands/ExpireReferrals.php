<?php

namespace App\Console\Commands;

use App\Services\ReferralService;
use Illuminate\Console\Command;

/**
 * Housekeeping only -- ReferralService::qualifyFromCompletedBooking() ALSO
 * independently guards on status='pending', so this never races a
 * legitimate reward: a referral that's actually about to qualify the
 * moment this runs either already got rewarded (this command's UPDATE
 * simply won't match it anymore) or it didn't (nothing to protect). Pure
 * housekeeping, same registration pattern as campaigns:dispatch-due/
 * plans:renew-due — not a second scheduling engine.
 */
class ExpireReferrals extends Command
{
    protected $signature = 'referrals:expire-due';

    protected $description = 'Marks pending referrals whose expires_at has passed as expired (opt-in — only referrals created while referral.pending_expiry_days was set have an expires_at at all)';

    public function handle(ReferralService $referralService): int
    {
        $count = $referralService->expirePendingReferrals();

        $this->info("Expired {$count} pending referral(s).");

        return self::SUCCESS;
    }
}
