<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingQuote;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-CANCEL-POLICY-001 scenario 6 — the professional's price quote, sent through the app after a verified
 * arrival. It is the record a quote-rejected cancel (with the visit charge) depends on: no in-app quote, no charge.
 * A newer quote expires any earlier unanswered one.
 */
class SendBookingQuoteAction
{
    /** @throws \RuntimeException */
    public function execute(int $bookingId, Provider $provider, float $amount): BookingQuote
    {
        if ($amount <= 0) {
            throw new \RuntimeException('Enter the quoted amount.');
        }

        $quote = DB::transaction(function () use ($bookingId, $provider, $amount) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $provider->id) {
                throw new \RuntimeException('This booking is not assigned to you.');
            }
            if ($booking->status !== 'provider_en_route' || $booking->arrival_verified_at === null) {
                throw new \RuntimeException('Check in at the address first, then send your quote.');
            }

            BookingQuote::where('booking_id', $booking->id)->where('status', 'sent')->update(['status' => 'expired', 'responded_at' => now()]);

            return BookingQuote::create([
                'booking_id' => $booking->id, 'provider_id' => $provider->id, 'amount' => round($amount, 2),
                'status' => 'sent', 'sent_at' => now(),
            ]);
        });

        try {
            \App\Support\Journey\StageNotifier::customer(Booking::find($quote->booking_id), 'quote_sent');
        } catch (\Throwable $e) {
            Log::warning("Quote notice failed for booking [{$quote->booking_id}]: ".$e->getMessage());
        }

        return $quote;
    }
}
