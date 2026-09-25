<?php

namespace App\Services\Earnings;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\WalletService;
use App\Support\EarningsSettings;
use App\Support\SuperAdminGate;
use Illuminate\Support\Str;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 3. Admin wallet corrections are NEW
 * compensating ledger rows (ref `admin-adjust:{uuid}`, actor_id = the admin),
 * never an edit of an existing row. Rules:
 *   - Super Admin only;
 *   - reason mandatory;
 *   - capped per adjustment by `wallet.admin_adjustment_max` (global only) —
 *     unset means adjustments are DISABLED, not uncapped;
 *   - a debit can never take the balance below zero (WalletService refuses);
 *   - a frozen wallet refuses both directions (WalletFreezePolicy);
 *   - every adjustment writes an activity_log entry.
 */
class WalletAdjustmentService
{
    public function __construct(private WalletService $wallet)
    {
    }

    public function adjust(User $admin, User $target, string $direction, float $amount, string $reason): WalletTransaction
    {
        SuperAdminGate::authorize($admin);

        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException('Direction must be credit or debit.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('A reason is required for every adjustment.');
        }

        $max = EarningsSettings::number('wallet.admin_adjustment_max');
        if ($max === null) {
            throw new \RuntimeException('Wallet adjustments are disabled: no maximum adjustment amount is configured.');
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \RuntimeException('Adjustment amount must be positive.');
        }
        if ($amount > $max) {
            throw new \RuntimeException("Adjustment exceeds the configured maximum of {$max}.");
        }

        $ref = 'admin-adjust:'.Str::uuid();
        $label = "Admin adjustment: {$reason}";

        $txn = $direction === 'credit'
            ? $this->wallet->credit($target, $amount, $label, $ref, $admin->id)
            : $this->wallet->debit($target, $amount, $label, $ref, $admin->id);

        ActivityLogger::log($admin, 'wallet', (int) $txn->wallet_id, "Admin wallet {$direction} of {$amount} for user #{$target->id}", [
            'target_user_id' => $target->id,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'ref' => $ref,
            'wallet_transaction_id' => $txn->id,
            'balance_after' => $this->wallet->balance($target),
        ]);

        return $txn;
    }
}
