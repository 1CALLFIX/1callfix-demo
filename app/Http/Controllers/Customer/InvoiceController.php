<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Documents\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Phase E6 — the web (session-guard) counterpart of
 * App\Http\Controllers\API\DocumentController::paymentDocument(). Same
 * engine (DocumentService + resources/views/documents/payment.blade.php),
 * same idempotent numbering, same 404-not-403 ownership rule — this only
 * adds the "resolve the payment FROM the booking in the URL" step so the
 * customer never has to know a payment id.
 *
 * The booking is route-model-bound; the ownership check is
 * booking->customer_id === the authed user. The payment is the booking's
 * own captured Payment, or — for a bundle child, which has no Payment of
 * its own (Phase E3 keeps one per bundle) — the bundle's aggregate Payment.
 */
class InvoiceController extends Controller
{
    public function show(Request $request, Booking $booking, DocumentService $documents)
    {
        abort_unless($booking->customer_id === $request->user()->id, 404);

        $payment = Payment::where('booking_id', $booking->id)
            ->whereIn('status', ['captured', 'paid'])
            ->latest('id')
            ->first();

        if (! $payment && $booking->booking_bundle_id) {
            $payment = Payment::where('booking_bundle_id', $booking->booking_bundle_id)
                ->where('purpose', 'booking_bundle')
                ->whereIn('status', ['captured', 'paid'])
                ->latest('id')
                ->first();
        }

        abort_unless($payment !== null, 404);

        $type = in_array($payment->status, ['captured', 'paid'], true) ? 'receipt' : 'invoice';
        $data = $documents->forPayment($payment, $type, $request->user());

        return Pdf::loadView('documents.payment', $data)
            ->stream(str_replace('/', '-', $data['number']).'.pdf');
    }
}
