<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\Wallet;
use Illuminate\Support\Collection;

/**
 * Reconciliation warnings (mission Phase 10, item 7). Strictly READ-ONLY —
 * identifies real inconsistencies by comparing existing tables against
 * each other, never mutates a financial record. Any actual repair is
 * explicitly out of scope here (a separately-authorized, auditable,
 * idempotent action, per the mission's own instruction) — this is the
 * "identify and report" half only.
 */
class ReconciliationService
{
    /** @return array{paid_bookings_without_captured_payment: Collection, completed_bookings_without_commission: Collection, wallet_balance_mismatches: Collection} */
    public function detect(): array
    {
        return [
            'paid_bookings_without_captured_payment' => $this->paidBookingsWithoutCapturedPayment(),
            'completed_bookings_without_commission' => $this->completedBookingsWithoutCommission(),
            'wallet_balance_mismatches' => $this->walletBalanceMismatches(),
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
}
