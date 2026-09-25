<?php

namespace App\Services\Earnings;

use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Support\SuperAdminGate;
use Illuminate\Support\Facades\DB;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D5. Freeze / unfreeze a customer or
 * provider wallet. Super Admin only, reason mandatory both ways, every
 * change audited. What a frozen wallet still accepts is decided by
 * WalletFreezePolicy inside WalletService, not here.
 */
class WalletFreezeService
{
    public function freeze(User $admin, User $target, string $reason): Wallet
    {
        SuperAdminGate::authorize($admin);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($admin, $target, $reason) {
            $wallet = Wallet::lockForUpdate()->firstOrCreate(['user_id' => $target->id], ['balance' => 0]);

            if ($wallet->frozen_at) {
                throw new \RuntimeException('This wallet is already frozen.');
            }

            $wallet->forceFill(['frozen_at' => now(), 'frozen_reason' => $reason, 'frozen_by' => $admin->id])->save();

            ActivityLogger::logModel($admin, $wallet, "Froze wallet of user #{$target->id}", [
                'target_user_id' => $target->id, 'reason' => $reason, 'balance' => (float) $wallet->balance,
            ]);

            return $wallet;
        });
    }

    public function unfreeze(User $admin, User $target, string $reason): Wallet
    {
        SuperAdminGate::authorize($admin);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($admin, $target, $reason) {
            $wallet = Wallet::lockForUpdate()->where('user_id', $target->id)->first();

            if (! $wallet || ! $wallet->frozen_at) {
                throw new \RuntimeException('This wallet is not frozen.');
            }

            $previous = ['frozen_at' => (string) $wallet->frozen_at, 'frozen_reason' => $wallet->frozen_reason, 'frozen_by' => $wallet->frozen_by];

            $wallet->forceFill(['frozen_at' => null, 'frozen_reason' => null, 'frozen_by' => null])->save();

            ActivityLogger::logModel($admin, $wallet, "Unfroze wallet of user #{$target->id}", [
                'target_user_id' => $target->id, 'reason' => $reason, 'previous' => $previous,
            ]);

            return $wallet;
        });
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('A reason is required.');
        }

        return $reason;
    }
}
