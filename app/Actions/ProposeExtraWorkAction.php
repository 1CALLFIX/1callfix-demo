<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingExtraItem;
use App\Models\Provider;
use App\Models\Setting;
use App\Support\Journey\StageNotifier;
use Illuminate\Support\Facades\DB;

class ProposeExtraWorkAction
{
    /** Sanity ceiling for a single extra-work request (rupees); an admin can tune it. */
    public const DEFAULT_MAX_AMOUNT = 100000;

    /**
     * Provider finds extra work mid-job (e.g. a ₹300 tap fitting booking, provider also finds a kitchen sink
     * leak, wants to charge ₹1000 more).
     *
     * Creates a pending extra-work item AND puts the booking on hold (customer_side /
     * awaiting_customer_approval) — reusing the hold layer rather than inventing a parallel status, since this
     * is genuinely the same "job paused, waiting on the customer" situation. The customer is told straight away
     * (every configured channel) and approves or declines from the order page / app.
     *
     * REF 1CF-EXTRAWORK-001 hardening: only a job that is IN PROGRESS can ask for extra work (resume always
     * returns to in_progress, so asking earlier would skip the start OTP), only one request can be open at a
     * time, and the amount must be a sane positive figure.
     *
     * @throws \RuntimeException if the job is not in progress, a request is already open, or the figures are invalid
     */
    public function execute(int $bookingId, Provider $provider, string $description, float $amount): BookingExtraItem
    {
        $description = trim($description);
        $max = (float) Setting::get('booking.extra_work_max_amount', self::DEFAULT_MAX_AMOUNT);

        if ($description === '' || mb_strlen($description) > 200) {
            throw new \RuntimeException('Describe the extra work in up to 200 characters.');
        }
        if ($amount <= 0 || $amount > $max) {
            throw new \RuntimeException('Enter an extra-work amount greater than 0 and no more than '.number_format($max, 0).'.');
        }

        $item = DB::transaction(function () use ($bookingId, $provider, $description, $amount) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $provider->id) {
                throw new \RuntimeException('This booking is not assigned to you.');
            }

            if ($booking->status !== 'in_progress') {
                throw new \RuntimeException('Extra work can be proposed once the job is in progress.');
            }

            if ($booking->extraItems()->where('status', 'pending_approval')->exists()) {
                throw new \RuntimeException('You already have an extra-work request waiting for the customer.');
            }

            $item = BookingExtraItem::create([
                'booking_id' => $bookingId,
                'description' => $description,
                'amount' => $amount,
                'status' => 'pending_approval',
                'added_by_provider_id' => $provider->id,
            ]);

            $currencySymbol = Setting::get('locale.currency_symbol', '₹');

            (new PlaceBookingOnHoldAction())->execute(
                $bookingId,
                'awaiting_customer_approval',
                "Extra work proposed: {$description} ({$currencySymbol}{$amount})"
            );

            return $item;
        });

        // The hold action's own customer 'on_hold' message is generic; this one carries the ask.
        StageNotifier::customer(Booking::find($bookingId), 'extra_work_proposed');

        return $item;
    }
}
