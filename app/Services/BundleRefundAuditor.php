<?php

namespace App\Services;

use App\Models\BookingBundle;
use App\Models\Payment;
use App\Models\WalletTransaction;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D3. READ-ONLY. Finds every cancelled
 * child of a paid bundle whose refund never landed: the bundle's shared
 * Payment is owed more than it has refunded (the E5.1 retained-amount math,
 * via BundleSettlementService::refundDue()), or — for a wallet-paid bundle —
 * the wallet refund rows don't add up to payments.refunded_amount.
 *
 * Shared by `bundles:refund-audit` and the Earnings Control monitoring
 * panel. Fixes nothing; the safe repair is re-running
 * BundleSettlementService::settleFromChildren(), which refunds exactly the
 * outstanding delta and nothing more.
 */
class BundleRefundAuditor
{
    public function __construct(private BundleSettlementService $settlement)
    {
    }

    /** @return array<int, array{bundle_id: int, bundle_code: string, gateway: string, cancelled_child_ids: array<int, int>, refund_due: float, refunded_amount: float, wallet_refunded: ?float, outstanding: float}> */
    public function findings(): array
    {
        $out = [];

        $payments = Payment::query()
            ->where('purpose', 'booking_bundle')
            ->whereNotNull('booking_bundle_id')
            ->whereIn('status', ['captured', 'partially_refunded', 'refunded'])
            ->get()
            ->keyBy('booking_bundle_id');

        if ($payments->isEmpty()) {
            return [];
        }

        $bundles = BookingBundle::query()
            ->with('children')
            ->whereIn('id', $payments->keys())
            ->whereHas('children', fn ($q) => $q->where('status', 'cancelled'))
            ->get();

        foreach ($bundles as $bundle) {
            $payment = $payments[$bundle->id];
            $due = $this->settlement->refundDue($bundle, $payment);
            $refunded = round((float) ($payment->refunded_amount ?? 0), 2);

            $walletRefunded = null;
            if ($payment->gateway === 'wallet') {
                $walletRefunded = round((float) WalletTransaction::query()
                    ->where('ref', 'like', "booking_bundle:{$bundle->id}:wallet-refund%")
                    ->where('is_credit', true)
                    ->sum('amount'), 2);
            }

            $outstanding = round(max($due - $refunded, 0), 2);
            $walletMismatch = $walletRefunded !== null && abs($walletRefunded - $refunded) > 0.001;

            if ($outstanding > 0 || $walletMismatch) {
                $out[] = [
                    'bundle_id' => $bundle->id,
                    'bundle_code' => (string) $bundle->code,
                    'gateway' => (string) $payment->gateway,
                    'cancelled_child_ids' => $bundle->children->where('status', 'cancelled')->pluck('id')->values()->all(),
                    'refund_due' => $due,
                    'refunded_amount' => $refunded,
                    'wallet_refunded' => $walletRefunded,
                    'outstanding' => $outstanding,
                ];
            }
        }

        return $out;
    }
}
