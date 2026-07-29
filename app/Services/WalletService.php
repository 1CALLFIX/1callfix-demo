<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    /**
     * Credit a user's wallet. Creates the wallet row on first use.
     * Wrapped in a DB transaction with a row lock so concurrent credits/debits
     * for the same wallet can't race each other into an incorrect balance.
     */
    public function credit(User $user, float $amount, string $reason, ?string $ref = null): WalletTransaction
    {
        return $this->applyTransaction($user, $amount, isCredit: true, reason: $reason, ref: $ref);
    }

    /**
     * Debit a user's wallet. Throws if the resulting balance would go negative —
     * wallets are not allowed to go into debt in this system.
     */
    public function debit(User $user, float $amount, string $reason, ?string $ref = null): WalletTransaction
    {
        return $this->applyTransaction($user, $amount, isCredit: false, reason: $reason, ref: $ref);
    }

    public function balance(User $user): float
    {
        return Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => 0])->balance;
    }

    private function applyTransaction(
        User $user,
        float $amount,
        bool $isCredit,
        string $reason,
        ?string $ref
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Wallet transaction amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $isCredit, $reason, $ref) {
            // lockForUpdate prevents a second concurrent transaction on this same
            // wallet from reading a stale balance while this one is in progress.
            $wallet = Wallet::lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0]
            );

            if (!$isCredit && $wallet->balance < $amount) {
                throw new \RuntimeException(
                    "Insufficient wallet balance for user [{$user->id}]: " .
                    "balance {$wallet->balance}, attempted debit {$amount}."
                );
            }

            $wallet->balance = $isCredit
                ? $wallet->balance + $amount
                : $wallet->balance - $amount;
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'is_credit' => $isCredit,
                'reason' => $reason,
                'ref' => $ref ?? (string) Str::uuid(),
                'status' => 'successful',
            ]);
        });
    }
}
