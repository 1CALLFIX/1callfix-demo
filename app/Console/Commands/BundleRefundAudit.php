<?php

namespace App\Console\Commands;

use App\Services\BundleRefundAuditor;
use Illuminate\Console\Command;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D3. READ-ONLY. Lists every cancelled
 * child of a paid bundle with no matching refund. Changes nothing; exit
 * code 1 when anything is listed.
 */
class BundleRefundAudit extends Command
{
    protected $signature = 'bundles:refund-audit';

    protected $description = 'Read-only: lists cancelled children of paid booking bundles whose refund is missing or short';

    public function handle(BundleRefundAuditor $auditor): int
    {
        $rows = $auditor->findings();

        if ($rows === []) {
            $this->info('No cancelled bundle child is missing a refund.');

            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $this->line(sprintf(
                'bundle #%d (%s, %s): cancelled children [%s] refund_due=%.2f refunded=%.2f%s outstanding=%.2f',
                $r['bundle_id'], $r['bundle_code'], $r['gateway'], implode(', ', $r['cancelled_child_ids']),
                $r['refund_due'], $r['refunded_amount'],
                $r['wallet_refunded'] !== null ? sprintf(' wallet_rows=%.2f', $r['wallet_refunded']) : '',
                $r['outstanding'],
            ));
        }

        $this->warn(count($rows).' bundle(s) listed. Nothing was changed.');

        return self::FAILURE;
    }
}
