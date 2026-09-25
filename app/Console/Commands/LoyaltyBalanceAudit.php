<?php

namespace App\Console\Commands;

use App\Services\Loyalty\LoyaltyBalanceAuditor;
use Illuminate\Console\Command;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D2. READ-ONLY. Lists every user whose
 * points balance under the pre-EARN3 formula differs from the FIFO balance,
 * or was negative under it. Fixes nothing — the decision on any historical
 * over-redemption stays with a human. Exit code 1 when anything is listed, so
 * a deploy script can notice.
 */
class LoyaltyBalanceAudit extends Command
{
    protected $signature = 'loyalty:balance-audit';

    protected $description = 'Read-only: lists users whose old-formula loyalty balance differs from the FIFO balance or is negative';

    public function handle(LoyaltyBalanceAuditor $auditor): int
    {
        $rows = $auditor->findings();

        if ($rows === []) {
            $this->info('No loyalty balance discrepancies found.');

            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $this->line(sprintf(
                'user #%d (%s): old=%d fifo=%d overdrawn=%d%s',
                $r['user_id'], $r['role'] ?? '?', $r['old_balance'], $r['fifo_balance'], $r['overdrawn'],
                $r['old_balance'] < 0 ? ' [OLD BALANCE NEGATIVE]' : '',
            ));
        }

        $this->warn(count($rows).' user(s) listed. Nothing was changed.');

        return self::FAILURE;
    }
}
