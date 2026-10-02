<?php

namespace App\Actions;

use App\Models\BookingQuote;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** REF 1CF-CANCEL-POLICY-001 scenario 6 — the customer accepts or rejects the professional's in-app quote. Answers are final. */
class RespondToBookingQuoteAction
{
    /**
     * @throws \RuntimeException
     * @throws ModelNotFoundException when the quote is not on this customer's booking
     */
    public function execute(int $quoteId, int $customerId, bool $accept): BookingQuote
    {
        return DB::transaction(function () use ($quoteId, $customerId, $accept) {
            $quote = BookingQuote::lockForUpdate()->with('booking')->findOrFail($quoteId);

            if (! $quote->booking || $quote->booking->customer_id !== $customerId) {
                throw new ModelNotFoundException('Quote not found.');
            }
            if ($quote->status !== 'sent') {
                throw new \RuntimeException("This quote was already {$quote->status}.");
            }

            $quote->update(['status' => $accept ? 'accepted' : 'rejected', 'responded_at' => now()]);

            return $quote->fresh();
        });
    }
}
