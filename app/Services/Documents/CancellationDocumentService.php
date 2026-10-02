<?php

namespace App\Services\Documents;

use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * REF 1CF-CANCEL-POLICY-001 step 7 — the cancellation invoice and credit note, built on the SAME engine as every other
 * document: DocumentNumberService (idempotent INV/… numbering, one row per documentable + type) and the ONE Blade template
 * `documents.payment`. The documentable is the cancelled Booking (a fee kept out of a prepaid payment has no Payment of its own).
 *
 *   invoice      the cancellation charge, once it has actually been collected
 *   credit_note  the part of a prepaid booking payment that was refunded
 *
 * Tax: this is exactly what the existing system does for a service charge — a single line carrying the amount, with NO GST
 * or tax breakdown (DocumentService adds none for any purpose). Nothing is invented here; if tax lines are introduced they
 * belong in the shared template and will then apply to these documents too.
 */
class CancellationDocumentService
{
    public function __construct(private DocumentNumberService $numbers)
    {
    }

    /** Was a cancellation charge actually collected for this booking? (kept from a prepaid payment, settled by wallet/gateway, or paid after the professional cancelled) */
    public function chargeCollected(Booking $booking): bool
    {
        if ($booking->status !== 'cancelled' || (float) $booking->cancellation_fee <= 0) {
            return false;
        }

        if (in_array($booking->payment_status, ['paid', 'partially_refunded', 'refunded'], true) && $this->bookingPayment($booking)) {
            return true; // taken from the prepaid amount
        }

        return BookingCancellationRequest::where('booking_id', $booking->id)->where('status', 'completed')->exists()
            || Payment::where('booking_id', $booking->id)->where('purpose', 'cancellation_fee')->where('status', 'captured')->exists();
    }

    /** The prepaid booking payment that was partly or fully refunded, if any. */
    public function refundedPayment(Booking $booking): ?Payment
    {
        $payment = $this->bookingPayment($booking);

        return ($payment && (float) $payment->refunded_amount > 0) ? $payment : null;
    }

    public function invoice(Booking $booking, ?User $by = null): ?array
    {
        if (! $this->chargeCollected($booking)) {
            return null;
        }

        $fee = round((float) $booking->cancellation_fee, 2);
        $reason = $booking->cancellation_fee_basis['code'] ?? null;

        return $this->build($booking, 'invoice', 'Cancellation Invoice', [[
            'label' => "Cancellation charge — Booking {$booking->code}".($reason ? ' ('.str_replace('_', ' ', $reason).')' : ''),
            'amount' => $fee,
        ]], $fee, 'paid', 0.0, $by);
    }

    public function creditNote(Booking $booking, ?User $by = null): ?array
    {
        $payment = $this->refundedPayment($booking);
        if (! $payment || $booking->status !== 'cancelled') {
            return null;
        }

        $refund = round((float) $payment->refunded_amount, 2);

        return $this->build($booking, 'credit_note', 'Credit Note', [[
            'label' => "Refund of booking payment — Booking {$booking->code}",
            'amount' => $refund,
        ]], $refund, 'refunded', 0.0, $by);
    }

    private function bookingPayment(Booking $booking): ?Payment
    {
        return Payment::where('booking_id', $booking->id)->where('purpose', 'booking')
            ->whereIn('status', ['captured', 'refunded', 'paid'])->latest('id')->first();
    }

    private function build(Booking $booking, string $type, string $title, array $lines, float $total, string $status, float $refunded, ?User $by): array
    {
        $booking->loadMissing(['customer', 'franchise.country']);
        $country = $booking->franchise?->country;
        $document = $this->numbers->numberFor($booking, $type, $country, $by);
        $timezone = $country?->default_timezone ?: config('app.timezone');
        $symbol = Setting::get('locale.currency_symbol', '₹', array_filter(['country_id' => $country?->id]));

        return [
            'number' => $document->number,
            'type' => $type,
            'title' => $title,
            'generated_at' => Carbon::now()->setTimezone($timezone),
            'currency_symbol' => $symbol,
            'payer_name' => $booking->customer?->name ?? 'Customer',
            'payer_phone' => $booking->customer?->phone,
            'franchise_name' => $booking->franchise?->name,
            'lines' => $lines,
            'total' => $total,
            'amount_words_currency_symbol' => $symbol,
            'payment_status' => $status,
            'gateway' => null,
            'gateway_ref' => null,
            'captured_at' => null,
            'refunded_amount' => $refunded,
        ];
    }
}
