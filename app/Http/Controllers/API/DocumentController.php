<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Documents\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Customer/Partner self-service invoice/receipt download. A payment
 * belongs to its payer alone — the payer is either the booking's customer
 * (purpose=booking) or the payment's own user_id (wallet_topup/
 * plan_subscription, see Payment's own docblock). 404s on any mismatch —
 * never confirms a payment ID's existence to a non-owner.
 */
class DocumentController extends Controller
{
    public function paymentDocument(Request $request, int $paymentId, DocumentService $documents)
    {
        $payment = Payment::with(['booking', 'user'])->find($paymentId);

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
        if ($payment->booking) {
            return $payment->booking->customer_id === $userId;
        }

        return $payment->user_id === $userId;
    }
}
