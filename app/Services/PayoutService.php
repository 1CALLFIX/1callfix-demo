<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns wallet balance into an actual money-movement request, auditable
 * end to end. Reuses WalletService for every balance change (no direct
 * balance mutation anywhere here) — see WalletService::applyTransaction()
 * for the row-locking/transaction guarantees this inherits.
 *
 * v1 scope, deliberately: there is no live payout-gateway integration
 * (Razorpay Route/similar) anywhere in this codebase, and building one
 * wasn't asked for or decided. The lifecycle below is the real, auditable
 * record-keeping half of disbursement — request holds the money out of the
 * payee's spendable wallet balance immediately (so it can't be
 * double-requested), an operator transfers it manually (bank/UPI, via the
 * payment_accounts row) outside this system, then records the outcome
 * here. `gateway_ref` stays free-text for that manual reference today; if
 * a real payout API gets integrated later, markPaid()'s caller is the only
 * thing that changes — the ledger shape underneath doesn't.
 */
class PayoutService
{
    public function __construct(private WalletService $walletService)
    {
    }

    /**
     * @param  string  $payeeType  'provider' (payee_id = providers.id) or 'franchise_owner' (payee_id = users.id)
     */
    public function request(string $payeeType, int $payeeId, float $amount, ?int $paymentAccountId = null): Payout
    {
        if (! in_array($payeeType, ['provider', 'franchise_owner'], true)) {
            throw new \InvalidArgumentException("Unknown payee_type [{$payeeType}].");
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payout amount must be positive.');
        }

        $user = $this->resolvePayeeUser($payeeType, $payeeId);

        return DB::transaction(function () use ($payeeType, $payeeId, $amount, $paymentAccountId, $user) {
            // Debit first (throws on insufficient balance) — a Payout row
            // only ever exists for money that's actually been set aside.
            $this->walletService->debit(
                $user,
                $amount,
                reason: 'Payout requested',
                ref: 'payout:'.Str::uuid()
            );

            return Payout::create([
                'payee_type' => $payeeType,
                'payee_id' => $payeeId,
                'payment_account_id' => $paymentAccountId,
                'amount' => $amount,
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
                'status' => 'pending',
            ]);
        });
    }

    public function markProcessing(Payout $payout): void
    {
        if ($payout->status !== 'pending') {
            throw new \RuntimeException("Payout #{$payout->id} is {$payout->status}, expected pending.");
        }

        $payout->update(['status' => 'processing']);
    }

    public function markPaid(Payout $payout, string $gatewayRef): void
    {
        if (! in_array($payout->status, ['pending', 'processing'], true)) {
            throw new \RuntimeException("Payout #{$payout->id} is {$payout->status}, expected pending/processing.");
        }

        $payout->update(['status' => 'paid', 'gateway_ref' => $gatewayRef, 'processed_at' => now()]);
    }

    /**
     * The money never actually left the platform, so it goes back into the
     * payee's wallet — same transaction-safe WalletService path as the
     * original debit, not a manual balance edit.
     */
    public function markFailed(Payout $payout, string $reason = ''): void
    {
        if (! in_array($payout->status, ['pending', 'processing'], true)) {
            throw new \RuntimeException("Payout #{$payout->id} is {$payout->status}, expected pending/processing.");
        }

        DB::transaction(function () use ($payout, $reason) {
            $user = $this->resolvePayeeUser($payout->payee_type, $payout->payee_id);

            $this->walletService->credit(
                $user,
                (float) $payout->amount,
                reason: 'Payout failed, refunded to wallet'.($reason ? ": {$reason}" : ''),
                ref: 'payout:'.$payout->id.':refund'
            );

            $payout->update(['status' => 'failed', 'processed_at' => now()]);
        });
    }

    public function resolvePayeeUser(string $payeeType, int $payeeId): User
    {
        return match ($payeeType) {
            'provider' => Provider::findOrFail($payeeId)->user
                ?? throw new \RuntimeException("Provider #{$payeeId} has no linked user."),
            'franchise_owner' => User::findOrFail($payeeId),
            default => throw new \InvalidArgumentException("Unknown payee_type [{$payeeType}]."),
        };
    }
}
