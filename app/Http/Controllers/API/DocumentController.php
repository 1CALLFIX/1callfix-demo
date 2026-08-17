<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Documents\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Customer/Partner self-service invoice/receipt download. A payment
 * belongs to its payer alone — the payer is the booking's customer
 * (purpose=booking), the payment's own user_id (wallet_topup/
 * plan_subscription, see Payment's own docblock), or the related order's
 * own customer (parcel_order/taxi_ride/property_reservation/
 * marketplace_order, each a real Orderable with its own customer_id).
 * 404s on any mismatch — never confirms a payment ID's existence to a
 * non-owner.
 *
 * **2026-08-17 hardening finding:** the four newer purposes were never
 * added to `belongsTo()` — a legitimate customer with a captured parcel/
 * taxi/property/marketplace payment could never successfully download
 * their own receipt through this endpoint (always 404, since neither
 * `booking` nor `user_id` is ever set for those purposes) even though the
 * document itself, once `DocumentService` was extended the same session,
 * renders correctly for them. Fixed alongside that extension.
 */
class DocumentController extends Controller
{
    public function paymentDocument(Request $request, int $paymentId, DocumentService $documents)
    {
        $payment = Payment::with(['booking', 'user', 'parcelOrder', 'taxiRide', 'propertyReservation', 'marketplaceOrder', 'rentalReservation', 'hotelReservation'])->find($paymentId);

        if (! $payment || ! $this->belongsTo($payment, $request->user()->id)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $type = in_array($payment->status, ['captured', 'paid'], true) ? 'receipt' : 'invoice';
        $data = $documents->forPayment($payment, $type, $request->user());

        $pdf = Pdf::loadView('documents.payment', $data);

        return $pdf->stream(str_replace('/', '-', $data['number']).'.pdf');
    }

    private function belongsTo(Payment $payment, int $userId): bool
    {
        return match ($payment->purpose) {
            'parcel_order' => $payment->parcelOrder?->customer_id === $userId,
            'taxi_ride' => $payment->taxiRide?->customer_id === $userId,
            'property_reservation' => $payment->propertyReservation?->customer_id === $userId,
            'marketplace_order' => $payment->marketplaceOrder?->customer_id === $userId,
            'rental_reservation' => $payment->rentalReservation?->customer_id === $userId,
            'hotel_reservation' => $payment->hotelReservation?->customer_id === $userId,
            default => $payment->booking
                ? $payment->booking->customer_id === $userId
                : $payment->user_id === $userId,
        };
    }
}
