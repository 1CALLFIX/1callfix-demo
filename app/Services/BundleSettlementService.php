<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\BookingBundle;
use App\Models\Payment;
use App\Notifications\PaymentStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase E5.1 — closes the two gaps Phase E7's QA found in the multi-service
 * bundle work (see PHASE_E_COMPLETION_REPORT.md §5).
 *
 * ── Gap 2: the status latch ──────────────────────────────────────────────
 * `BookingBundle.status` is a stored enum (active/completed/cancelled) that
 * no code ever advanced from 'active'. `latchTerminalStatus()` performs the
 * one-way active -> terminal write, driven ENTIRELY by the E1
 * `derivedStatus()` cross-child view — no new status rule is invented here.
 *
 * ── Gap 1: the bundle refund ─────────────────────────────────────────────
 * E3 puts ONE Payment on the bundle (purpose='booking_bundle',
 * booking_bundle_id set), never one per child, so
 * `CancellationService::refundIfPaid()` — which looks up
 * `Payment::where('booking_id', ...)` — is a no-op for a bundle child, and
 * cancelling a paid bundle child refunded nothing.
 *
 * `reconcileRefund()` resolves the shared bundle Payment and refunds it down
 * to the amount that must be RETAINED, using the SAME per-item rule
 * `refundIfPaid()` uses for a standalone booking — keep the cancellation
 * fee, return the rest — just summed across the children and guarded against
 * a double refund by `payments.refunded_amount`.
 *
 * Retained per child (mirrors `refundIfPaid()`'s `payment.amount - fee`):
 *   - cancelled child ...... its own `cancellation_fee` — the identical fee
 *                            `CancellationService::calculateFee()` already
 *                            computed for it inside `AdminCancelBookingAction`,
 *                            exactly what a standalone cancel would keep.
 *   - completed child ...... its full `price_quoted` share of the frozen
 *                            bundle total — the service was delivered, there
 *                            is nothing to refund. `price_quoted`, not
 *                            `price_final`: approved extras are settled
 *                            outside the frozen bundle Payment.
 *   - still-active child ... its full `price_quoted` — not cancelled yet, so
 *                            nothing is refundable for it yet; a later cancel
 *                            reconciles again.
 *
 *   refundDue = max(payment.amount - Σ retained, 0)
 *   refundNow = max(refundDue - payment.refunded_amount, 0)
 *
 * Idempotent: re-running once everything is reconciled refunds 0 and
 * re-latches nothing.
 */
class BundleSettlementService
{
    /** @var array<int, string> child booking statuses that are terminal */
    private const TERMINAL = ['completed', 'cancelled'];

    public function __construct(
        private WalletService $wallet,
        private PaymentGateway $gateway,
    ) {
    }

    /**
     * Latch the stored status AND reconcile the shared bundle Payment, in one
     * locked transaction. Safe to call after ANY bundle child reaches a
     * terminal state — from `CompleteBookingAction`, `AdminCancelBookingAction`
     * or `CancelBookingBundleAction`. A completion never produces a refund
     * (no child was cancelled); only the latch runs in that case.
     *
     * @return float|null the amount refunded on THIS call, or null if nothing was refunded
     */
    public function settleFromChildren(int $bundleId): ?float
    {
        return DB::transaction(function () use ($bundleId) {
            /** @var BookingBundle|null $bundle */
            $bundle = BookingBundle::query()->whereKey($bundleId)->lockForUpdate()->first();

            if (! $bundle) {
                return null;
            }

            $bundle->load('children');

            $this->latchTerminalStatus($bundle);

            return $this->reconcileRefund($bundle);
        });
    }

    /**
     * Gap 2. One-way `active` -> `completed`/`cancelled`, decided solely by
     * `BookingBundle::derivedStatus()` (E1). No-op unless the bundle is still
     * `active` and every child is now terminal. Assumes `$bundle` is
     * row-locked and its `children` relation is loaded.
     */
    public function latchTerminalStatus(BookingBundle $bundle): bool
    {
        if ($bundle->status !== 'active') {
            return false;
        }

        $derived = $bundle->derivedStatus();

        if (! in_array($derived, self::TERMINAL, true)) {
            return false;
        }

        $bundle->status = $derived;
        $bundle->save();

        return true;
    }

    /**
     * Gap 1. Refund the ONE shared bundle Payment down to the retained
     * amount. Assumes `$bundle` is row-locked and its `children` relation is
     * loaded. Returns the amount refunded on this call, or null for a no-op
     * (no captured bundle Payment, or nothing left owing).
     */
    public function reconcileRefund(BookingBundle $bundle): ?float
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('booking_bundle_id', $bundle->id)
            ->where('purpose', 'booking_bundle')
            ->whereIn('status', ['captured', 'partially_refunded'])
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if (! $payment) {
            // cash bundle (no Payment row) or still pending / already fully
            // refunded — nothing to reconcile.
            return null;
        }

        $retained = 0.0;
        foreach ($bundle->children as $child) {
            $retained += $child->status === 'cancelled'
                ? (float) ($child->cancellation_fee ?? 0)
                : (float) ($child->price_quoted ?? 0);
        }
        $retained = round($retained, 2);

        $paid = (float) $payment->amount;
        $alreadyRefunded = (float) ($payment->refunded_amount ?? 0);

        $refundDue = round(max($paid - $retained, 0), 2);
        $refundNow = round(max($refundDue - $alreadyRefunded, 0), 2);

        if ($refundNow <= 0) {
            return null;
        }

        if ($payment->gateway === 'wallet') {
            $this->wallet->credit(
                $bundle->customer,
                $refundNow,
                reason: "Refund for cancelled booking bundle {$bundle->code}",
                ref: "booking_bundle:{$bundle->id}:wallet-refund",
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundNow,
                "Booking bundle {$bundle->code} cancelled",
            );
        }

        $totalRefunded = round($alreadyRefunded + $refundNow, 2);

        $payment->refunded_amount = $totalRefunded;
        $payment->status = $totalRefunded >= $paid ? 'refunded' : 'partially_refunded';
        $payment->save();

        $bundle->payment_status = $totalRefunded >= $paid ? 'refunded' : 'partially_refunded';
        $bundle->save();

        if ($bundle->customer && $bundle->children->isNotEmpty()) {
            try {
                $channels = ChannelResolver::resolve([
                    'zone_id' => $bundle->zone_id,
                    'franchise_id' => $bundle->franchise_id,
                ]);
                $bundle->customer->notify(new PaymentStatusNotification(
                    'refunded', $bundle->children->first(), $channels, $refundNow,
                ));
            } catch (\Throwable $e) {
                Log::error("Phase E5.1: bundle refund notification failed for bundle [{$bundle->id}]: ".$e->getMessage());
            }
        }

        return $refundNow;
    }
}
