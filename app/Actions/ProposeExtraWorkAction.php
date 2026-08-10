<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingExtraItem;
use App\Models\Provider;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class ProposeExtraWorkAction
{
    /**
     * Provider finds extra work mid-job (e.g. a ₹300 tap fitting booking,
     * provider also finds a kitchen sink leak, wants to charge ₹1000 more).
     *
     * Creates a pending extra-work item AND automatically puts the booking
     * on hold (customer_side / awaiting_customer_approval) — reusing the
     * hold layer rather than inventing a parallel status, since this is
     * genuinely the same "job paused, waiting on the customer" situation.
     *
     * @throws \RuntimeException if the booking isn't in an active state
     */
    public function execute(int $bookingId, Provider $provider, string $description, float $amount): BookingExtraItem
    {
        return DB::transaction(function () use ($bookingId, $provider, $description, $amount) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $provider->id) {
                throw new \RuntimeException('This booking is not assigned to you.');
            }

            if (!in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress'], true)) {
                throw new \RuntimeException(
                    "Cannot propose extra work on a booking with status '{$booking->status}'."
                );
            }

            $item = BookingExtraItem::create([
                'booking_id' => $bookingId,
                'description' => $description,
                'amount' => $amount,
                'status' => 'pending_approval',
                'added_by_provider_id' => $provider->id,
            ]);

            // Reuse the existing hold action — this genuinely is the same
            // "customer_side, awaiting_customer_approval" situation the hold
            // layer was built for, not a separate mechanism.
            $currencySymbol = Setting::get('locale.currency_symbol', '₹');

            (new PlaceBookingOnHoldAction())->execute(
                $bookingId,
                'awaiting_customer_approval',
                "Extra work proposed: {$description} ({$currencySymbol}{$amount})"
            );

            return $item;
        });
    }
}
