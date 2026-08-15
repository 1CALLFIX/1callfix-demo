<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;

/**
 * Reconciliation warnings (mission Phase 10, item 7; extended mission
 * Phase 15 — financial reconciliation audit). Strictly READ-ONLY —
 * identifies real inconsistencies by comparing existing tables against
 * each other, never mutates a financial record. Any actual repair is
 * explicitly out of scope here (a separately-authorized, auditable,
 * idempotent action, per the mission's own instruction) — this is the
 * "identify and report" half only.
 */
class ReconciliationService
{
    /** @return array{paid_bookings_without_captured_payment: Collection, completed_bookings_without_commission: Collection, wallet_balance_mismatches: Collection, wallet_topups_captured_without_credit: Collection, negative_loyalty_balances: Collection} */
    public function detect(): array
    {
        return [
            'paid_bookings_without_captured_payment' => $this->paidBookingsWithoutCapturedPayment(),
            'completed_bookings_without_commission' => $this->completedBookingsWithoutCommission(),
            'wallet_balance_mismatches' => $this->walletBalanceMismatches(),
            'wallet_topups_captured_without_credit' => $this->walletTopupsCapturedWithoutCredit(),
            'negative_loyalty_balances' => $this->negativeLoyaltyBalances(),
        ];
    }

    /** A booking marked payment_status='paid' should always have a matching captured Payment row -- if it doesn't, either the webhook never landed or something wrote payment_status directly. */
    private function paidBookingsWithoutCapturedPayment(): Collection
    {
        return Booking::where('payment_status', 'paid')
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', 'captured'))
            ->with('customer')
            ->latest('id')
            ->limit(100)
            ->get();
    }

    /** CompleteBookingAction always calls CommissionService::applyForBooking() -- a completed booking with no commission row means that step never ran (or ran and was rolled back oddly). */
    private function completedBookingsWithoutCommission(): Collection
    {
        return Booking::where('status', 'completed')
            ->whereDoesntHave('commission')
            ->with('customer', 'provider.user')
            ->latest('id')
            ->limit(100)
            ->get();
    }

    /** wallets.balance is a stored running total (WalletService keeps it in sync under a row lock on every transaction) -- if it ever drifts from SUM(wallet_transactions), something bypassed WalletService. Flags mismatches beyond a tiny float-rounding tolerance. */
    private function walletBalanceMismatches(): Collection
    {
        return Wallet::with('user')
            ->get()
            ->map(function (Wallet $wallet) {
                $ledgerSum = $wallet->transactions()
                    ->where('status', 'successful')
                    ->get()
                    ->sum(fn ($t) => $t->is_credit ? (float) $t->amount : -(float) $t->amount);

                return ['wallet' => $wallet, 'stored_balance' => (float) $wallet->balance, 'ledger_sum' => round($ledgerSum, 2)];
            })
            ->filter(fn ($row) => abs($row['stored_balance'] - $row['ledger_sum']) > 0.01)
            ->values();
    }

    /**
     * Phase 15 finding: RazorpayWebhookHandler::handleCaptured() commits
     * `Payment.status = 'captured'` inside its own DB::transaction(), then
     * calls WalletTopUpService::creditWalletForCapturedTopUp() (which
     * itself opens a SEPARATE transaction via WalletService) as a later,
     * non-atomic step. If the process dies between those two commits, a
     * customer's Razorpay payment is captured with no matching wallet
     * credit ever landing — real money paid, never delivered. No direct
     * Eloquent relation links Payment to the WalletTransaction it should
     * have produced (the ref is a deterministic "topup:{payment_id}"
     * string, not a FK), so this checks existence per-row rather than a
     * single join, same style walletBalanceMismatches() already uses.
     */
    private function walletTopupsCapturedWithoutCredit(): Collection
    {
        return Payment::where('purpose', 'wallet_topup')
            ->where('status', 'captured')
            ->with('user')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reject(fn (Payment $payment) => WalletTransaction::where('ref', "topup:{$payment->id}")->exists())
            ->values();
    }

    /**
     * Phase 15 finding: LoyaltyService::redeem() used to check the
     * unlocked SUM() balance before opening its transaction — two
     * concurrent redemptions for the same user could both pass and both
     * credit the wallet, driving the aggregate ledger negative (fixed this
     * phase with a row lock, matching WalletService's own convention; this
     * check is the detection half, catching anything that slipped through
     * before the fix or via a future bypass). Mirrors
     * LoyaltyService::balance()'s own expiry-aware SUM() exactly.
     */
    private function negativeLoyaltyBalances(): Collection
    {
        return LoyaltyPoint::query()
            ->select('user_id')
            ->selectRaw('SUM(points) as balance')
            ->where(function ($q) {
                $q->where('points', '<', 0)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->groupBy('user_id')
            ->having('balance', '<', 0)
            ->with('user')
            ->get();
    }
}
