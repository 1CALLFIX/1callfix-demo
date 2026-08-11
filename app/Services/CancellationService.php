<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;

/**
 * Cancellation policy resolution + the fee/refund calculation it drives.
 * Shared by AdminCancelBookingAction today; deliberately not admin-only in
 * shape (calculateFee() only takes a Booking, no admin context) so a future
 * customer-initiated cancellation action can call the exact same method
 * rather than a second implementation.
 *
 * Policy values are read through Setting::get()'s existing Global→Country→
 * City→Zone→Module→Franchise cascade — no new resolver, no new
 * cancellation_policies-table logic. cancellation_policies stays in the
 * schema, unused (same status as before this class existed).
 */
class CancellationService
{
    public function __construct(private RazorpayService $razorpay)
    {
    }

    /**
     * Elapsed time is measured from booking.created_at, not provider
     * assignment — confirmed decision. Fee never exceeds the quoted price.
     */
    public function calculateFee(Booking $booking): float
    {
        $booking->loadMissing('franchise');

        $scope = array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);

        $freeMinutes = (int) Setting::get('cancellation.free_minutes', '15', $scope);
        $feeType = Setting::get('cancellation.fee_type', 'flat', $scope);
        $feeValue = (float) Setting::get('cancellation.fee_value', '0', $scope);

        $elapsedMinutes = $booking->created_at->diffInMinutes(now());

        if ($elapsedMinutes <= $freeMinutes) {
            return 0.0;
        }

        $basis = (float) ($booking->price_quoted ?? 0);
        $fee = $feeType === 'percent' ? round($basis * $feeValue / 100, 2) : $feeValue;

        return round(min($fee, $basis), 2);
    }

    /**
     * If the booking has a captured payment, refunds (amount paid - fee)
     * through Razorpay and records it on the existing payments/bookings
     * columns. No-op for an unpaid booking — there's nothing to refund,
     * and today 'cash'/'wallet' payment_method bookings never get a
     * captured Payment row in the first place (only the Razorpay
     * create-order/webhook path does), so this only ever fires for real
     * online payments.
     */
    public function refundIfPaid(Booking $booking, float $fee): void
    {
        $payment = Payment::where('booking_id', $booking->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (!$payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            // The fee consumed the entire payment — nothing left to hand
            // back, and calling Razorpay with a zero/negative amount would
            // be meaningless. payment_status stays 'paid'; cancellation_fee
            // on the booking already records that the full amount was kept.
            return;
        }

        $this->razorpay->refund(
            $payment->gateway_payment_id,
            $refundAmount,
            "Booking {$booking->code} cancelled"
        );

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $booking->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $booking->save();
    }
}
