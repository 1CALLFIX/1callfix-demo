<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Exceptions\WalletFrozenException;
use App\Support\WalletFreezePolicy;

class WalletService
{
    /**
     * Credit a user's wallet. Creates the wallet row on first use.
     * Wrapped in a DB transaction with a row lock so concurrent credits/debits
     * for the same wallet can't race each other into an incorrect balance.
     */
    public function credit(User $user, float $amount, string $reason, ?string $ref = null, ?int $actorId = null): WalletTransaction
    {
        return $this->applyTransaction($user, $amount, isCredit: true, reason: $reason, ref: $ref, actorId: $actorId);
    }

    /**
     * Debit a user's wallet. Throws if the resulting balance would go negative —
     * wallets are not allowed to go into debt in this system.
     */
    public function debit(User $user, float $amount, string $reason, ?string $ref = null, ?int $actorId = null): WalletTransaction
    {
        return $this->applyTransaction($user, $amount, isCredit: false, reason: $reason, ref: $ref, actorId: $actorId);
    }

    /** EARN3 D5 — is this user's wallet frozen right now? (No wallet row = not frozen.) */
    public function isFrozen(User $user): bool
    {
        return Wallet::where('user_id', $user->id)->whereNotNull('frozen_at')->exists();
    }

    /** Throw the same WalletFrozenException the ledger itself would, before any other work starts. */
    public function assertNotFrozen(User $user): void
    {
        if ($this->isFrozen($user)) {
            throw new WalletFrozenException();
        }
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
        ?string $ref,
        ?int $actorId = null
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Wallet transaction amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $isCredit, $reason, $ref, $actorId) {
            // lockForUpdate prevents a second concurrent transaction on this same
            // wallet from reading a stale balance while this one is in progress.
            $wallet = Wallet::lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0]
            );

            // EARN3 D5 — decided under the row lock, by the row's source, so
            // no caller can route around it (see WalletFreezePolicy).
            if ($wallet->frozen_at && WalletFreezePolicy::blocks($isCredit, $ref)) {
                throw new WalletFrozenException();
            }

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
                'actor_id' => $actorId,
                'status' => 'successful',
            ]);
        });
    }
}
