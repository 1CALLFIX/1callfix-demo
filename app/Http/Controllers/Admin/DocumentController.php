<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\AuthorizationService;
use App\Services\Documents\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Admin-side invoice/receipt streaming — reuses payments.view's EXISTING
 * row-level scope (the same dual-path booking/user scopeQuery() columns
 * Payments\Index already established), not a new permission or a second
 * scoping mechanism. 404s (not 403) on a scope mismatch, same information-
 * hiding convention as every other admin document/detail lookup this
 * session (KycDocumentController, Bookings\Show::mount()).
 */
class DocumentController extends Controller
{
    /**
     * 2026-08-17: extended to the four newer Orderable purposes, same fix
     * and reasoning as Payments\Index::scopeColumns().
     * RENTAL MODULE IMPLEMENTATION: extended again to rentalReservation
     * (the shared Vehicle/Equipment engine's own purpose) -- same reasoning,
     * same fix, same commit-history pattern.
     */
    private function scopeColumns(): array
    {
        return [
            'zone_id' => ['booking.zone_id', 'user.zone_id', 'parcelOrder.zone_id', 'taxiRide.zone_id', 'propertyReservation.zone_id', 'marketplaceOrder.zone_id', 'rentalReservation.zone_id', 'hotelReservation.zone_id'],
            'franchise_id' => ['booking.franchise_id', 'user.franchise_id', 'parcelOrder.franchise_id', 'taxiRide.franchise_id', 'propertyReservation.franchise_id', 'marketplaceOrder.franchise_id', 'rentalReservation.franchise_id', 'hotelReservation.franchise_id'],
            'city_id' => ['booking.franchise.city_id', 'user.franchise.city_id', 'parcelOrder.franchise.city_id', 'taxiRide.franchise.city_id', 'propertyReservation.franchise.city_id', 'marketplaceOrder.franchise.city_id', 'rentalReservation.franchise.city_id', 'hotelReservation.franchise.city_id'],
            'country_id' => ['booking.franchise.country_id', 'user.franchise.country_id', 'parcelOrder.franchise.country_id', 'taxiRide.franchise.country_id', 'propertyReservation.franchise.country_id', 'marketplaceOrder.franchise.country_id', 'rentalReservation.franchise.country_id', 'hotelReservation.franchise.country_id'],
        ];
    }

    public function paymentDocument(int $paymentId, DocumentService $documents)
    {
        $payment = app(AuthorizationService::class)
            ->scopeQuery(Payment::query(), auth()->user(), 'payments.view', $this->scopeColumns())
            ->find($paymentId);

        abort_if(! $payment, 404);

        $type = in_array($payment->status, ['captured', 'paid'], true) ? 'receipt' : 'invoice';
        $data = $documents->forPayment($payment, $type, auth()->user());

        $pdf = Pdf::loadView('documents.payment', $data);

        // The document number itself contains "/" (e.g. INV/IN/2026/000123)
        // -- legible for display, but not a legal filename/Content-
        // Disposition value, so the download filename swaps it for "-".
        return $pdf->stream(str_replace('/', '-', $data['number']).'.pdf');
    }
}
