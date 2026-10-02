<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Cancellation\CancellationBlockedException;
use App\Services\Cancellation\CancellationPolicy;
use App\Contracts\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-CANCEL-POLICY-001 — an admin waives a customer's pending cancellation charge (unpaid for 7 days, a goodwill
 * decision, a dispute outcome...). Completes the cancellation with NO charge, refunds anything the customer already paid
 * against the charge, and logs who waived it and why. The mid-work lock still applies: a waive cannot cancel a job
 * that is in progress (an operator uses the normal admin cancel for that).
 */
class WaiveCancellationChargeAction
{
    public function __construct(private AdminCancelBookingAction $cancel, private CancellationPolicy $policy, private PaymentGateway $gateway)
    {
    }

    /** @throws \RuntimeException */
    public function execute(int $requestId, User $admin, string $reason): Booking
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Give a reason for waiving the charge — it is logged.');
        }

        $request = BookingCancellationRequest::findOrFail($requestId);
        if (! in_array($request->status, ['awaiting_payment', 'awaiting_admin'], true)) {
            throw new \RuntimeException("This cancellation request is already {$request->status}.");
        }

        $booking = Booking::findOrFail($request->booking_id);

        if ($booking->status === 'cancelled') {
            // A charge the professional raised when they cancelled (no-show / quote rejected): the cancellation already
            // happened, so waiving just removes the charge from the booking. The professional is not paid for a waived charge.
            $cancelled = DB::transaction(function () use ($booking, $admin, $reason, $request) {
                $locked = Booking::lockForUpdate()->findOrFail($booking->id);
                $locked->update([
                    'cancellation_fee' => 0,
                    'cancellation_fee_basis' => ($locked->cancellation_fee_basis ?? []) + ['waived_by' => $admin->id, 'waive_reason' => $reason, 'quoted_charge' => (float) $request->total_charge],
                ]);

                return $locked->fresh();
            });
        } else {
            $cancelled = $this->cancel->execute(
                $request->booking_id,
                (string) $request->reason,
                feeResolver: function (Booking $locked) use ($admin, $reason) {
                    $decision = $this->policy->evaluate($locked);
                    if (! $decision['allowed']) {
                        throw new CancellationBlockedException($decision);
                    }

                    return [0.0, ['code' => 'admin_waived', 'waived_by' => $admin->id, 'reason' => $reason, 'quoted_charge' => $decision['charge']]];
                },
                cancelledByRole: 'customer',
            );
        }

        DB::transaction(function () use ($request, $admin, $reason) {
            $request->update(['status' => 'waived', 'resolved_by' => $admin->id, 'resolution_note' => $reason]);
        });

        // Anything the customer already paid against the charge goes straight back.
        $payment = $request->payment_id ? \App\Models\Payment::find($request->payment_id) : null;
        if ($payment && $payment->status === 'captured' && $payment->gateway_payment_id) {
            try {
                $this->gateway->refund($payment->gateway_payment_id, (float) $payment->amount, 'Cancellation charge waived');
                $payment->update(['status' => 'refunded', 'refunded_amount' => $payment->amount]);
            } catch (\Throwable $e) {
                Log::error("Refund after waiving cancellation charge failed (payment [{$payment->id}]): ".$e->getMessage());
            }
        }

        ActivityLogger::logModel($admin, $cancelled, 'cancellation charge waived', ['request_id' => $request->id, 'quoted_charge' => (float) $request->total_charge, 'reason' => $reason]);

        return $cancelled;
    }
}
