<?php

namespace App\Services;

use App\Models\ReferralBalance;
use App\Models\ReferralBalanceTransaction;
use App\Models\User;
use App\Support\EarningsSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-10-17 — the referral balance: money earned through
 * referrals, kept apart from the customer's own wallet.
 *
 *   - Never withdrawable: nothing pays out from here (PayoutService reads the wallet only).
 *   - Spent oldest-expiry-first, so a grant that expires sooner is used first.
 *   - A refund of a booking part paid from here comes back here (refund()), never to the wallet or bank.
 *   - Expiry: referral.balance_expiry_days at the time of the grant (blank = never expires).
 *
 * Every move is under a row lock and keyed by a unique `ref`, so a repeated call never moves money twice.
 */
class ReferralBalanceService
{
    /** What the user can spend right now (unexpired, unspent grants). */
    public function available(User $user): float
    {
        $balance = ReferralBalance::where('user_id', $user->id)->first();
        if (! $balance) {
            return 0.0;
        }

        return round((float) $this->openGrants($balance)->sum('remaining'), 2);
    }

    /** A referral reward (or a refund of a referral-paid part) lands here. Idempotent on $ref. */
    public function credit(User $user, float $amount, string $reason, string $ref, ?int $bookingId = null, ?Carbon $expiresAt = null, ?int $actorId = null): ReferralBalanceTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Referral balance amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $reason, $ref, $bookingId, $expiresAt, $actorId) {
            $balance = $this->lockedBalance($user);

            if ($existing = ReferralBalanceTransaction::where('ref', $ref)->first()) {
                return $existing;
            }

            if ($expiresAt === null) {
                $days = EarningsSettings::integer('referral.balance_expiry_days');
                $expiresAt = $days !== null && $days > 0 ? now()->addDays($days) : null;
            }

            $balance->balance = round((float) $balance->balance + $amount, 2);
            $balance->save();

            return $balance->transactions()->create([
                'amount' => $amount, 'is_credit' => true, 'remaining' => $amount, 'expires_at' => $expiresAt,
                'reason' => $reason, 'ref' => $ref, 'booking_id' => $bookingId, 'actor_id' => $actorId,
            ]);
        });
    }

    /**
     * Spend exactly $amount (booking payment). Throws if less than that is available.
     * Idempotent on $ref: a repeat returns the first debit.
     */
    public function debit(User $user, float $amount, string $reason, string $ref, ?int $bookingId = null, ?int $actorId = null): ReferralBalanceTransaction
    {
        return $this->spend($user, $amount, $reason, $ref, $bookingId, $actorId, partial: false);
    }

    /** Take back up to $amount (fraud clawback). Never goes below zero; returns null when nothing was available. */
    public function debitUpTo(User $user, float $amount, string $reason, string $ref, ?int $actorId = null): ?ReferralBalanceTransaction
    {
        return $this->spend($user, $amount, $reason, $ref, null, $actorId, partial: true);
    }

    /** Expire every grant past its expiry date. Returns how many grants were expired. Safe to run repeatedly. */
    public function expireDue(): int
    {
        $count = 0;
        $due = ReferralBalanceTransaction::where('is_credit', true)->where('remaining', '>', 0)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->pluck('id');

        foreach ($due as $grantId) {
            DB::transaction(function () use ($grantId, &$count) {
                $grant = ReferralBalanceTransaction::lockForUpdate()->find($grantId);
                if (! $grant || (float) $grant->remaining <= 0) {
                    return;
                }
                $balance = ReferralBalance::lockForUpdate()->find($grant->referral_balance_id);
                $left = (float) $grant->remaining;

                $grant->remaining = 0;
                $grant->save();
                $balance->balance = round(max(0, (float) $balance->balance - $left), 2);
                $balance->save();

                $balance->transactions()->create([
                    'amount' => $left, 'is_credit' => false, 'reason' => 'Referral balance expired',
                    'ref' => "referral-balance:expire:{$grant->id}",
                ]);
                $count++;
            });
        }

        return $count;
    }

    private function spend(User $user, float $amount, string $reason, string $ref, ?int $bookingId, ?int $actorId, bool $partial): ?ReferralBalanceTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Referral balance amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $reason, $ref, $bookingId, $actorId, $partial) {
            $balance = $this->lockedBalance($user);

            if ($existing = ReferralBalanceTransaction::where('ref', $ref)->first()) {
                return $existing;
            }

            $grants = $this->openGrants($balance)->lockForUpdate()->get();
            $available = round((float) $grants->sum('remaining'), 2);
            $take = $partial ? min($amount, $available) : $amount;

            if ($take <= 0) {
                return null;
            }
            if (! $partial && $available + 0.0001 < $amount) {
                throw new \RuntimeException("Not enough referral balance: available {$available}, needed {$amount}.");
            }

            $left = $take;
            foreach ($grants as $grant) {
                if ($left <= 0) {
                    break;
                }
                $use = min($left, (float) $grant->remaining);
                $grant->remaining = round((float) $grant->remaining - $use, 2);
                $grant->save();
                $left = round($left - $use, 2);
            }

            $balance->balance = round(max(0, (float) $balance->balance - $take), 2);
            $balance->save();

            return $balance->transactions()->create([
                'amount' => $take, 'is_credit' => false, 'reason' => $reason, 'ref' => $ref,
                'booking_id' => $bookingId, 'actor_id' => $actorId,
            ]);
        });
    }

    private function lockedBalance(User $user): ReferralBalance
    {
        ReferralBalance::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return ReferralBalance::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    /** Unspent, unexpired grants, the one expiring soonest first (never-expiring last). */
    private function openGrants(ReferralBalance $balance)
    {
        return ReferralBalanceTransaction::where('referral_balance_id', $balance->id)
            ->where('is_credit', true)->where('remaining', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id');
    }
}
