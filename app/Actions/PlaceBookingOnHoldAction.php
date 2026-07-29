<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

class PlaceBookingOnHoldAction
{
    private const CUSTOMER_SIDE_REASONS = [
        'awaiting_spares',
        'awaiting_customer_approval',
        'awaiting_payment_decision',
        'other_customer_issue',
    ];

    private const PROVIDER_SIDE_REASONS = [
        'provider_unresponsive',
        'payment_not_reconciled',
        'other_provider_issue',
    ];

    /**
     * Puts an in-progress booking on hold. The category (customer_side vs.
     * provider_side) is derived automatically from the reason, not passed in
     * separately — this guarantees a booking can never end up mis-categorized
     * by a caller passing an inconsistent category/reason pair.
     *
     * customer_side: routine, provider stays assigned, follow-up call goes to
     *   the customer to unblock it.
     * provider_side: red flag, urgent — surfaces on a priority admin queue,
     *   follow-up call goes to the provider. Feeds into provider reliability
     *   tracking (rating_avg / provider_badges) in a later phase.
     *
     * @throws \InvalidArgumentException for an unrecognized reason
     * @throws \RuntimeException if the booking isn't in a holdable state
     */
    public function execute(int $bookingId, string $reason, ?string $note = null): Booking
    {
        $category = match (true) {
            in_array($reason, self::CUSTOMER_SIDE_REASONS, true) => 'customer_side',
            in_array($reason, self::PROVIDER_SIDE_REASONS, true) => 'provider_side',
            default => throw new \InvalidArgumentException("Unrecognized hold reason: {$reason}"),
        };

        return DB::transaction(function () use ($bookingId, $reason, $note, $category) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (!in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress'], true)) {
                throw new \RuntimeException(
                    "Booking [{$bookingId}] cannot be put on hold from status '{$booking->status}' — " .
                    "only active, assigned bookings can be held."
                );
            }

            $booking->status = 'on_hold';
            $booking->hold_category = $category;
            $booking->hold_reason = $reason;
            $booking->hold_note = $note;
            $booking->on_hold_since = now();
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'on_hold',
                'changed_by' => $booking->provider?->user_id,
                'note' => "Hold reason: {$reason}" . ($note ? " — {$note}" : ''),
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });
    }
}
