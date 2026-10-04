<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Journey\StageNotifier;
use Illuminate\Support\Facades\DB;

/**
 * REF 1CF-CANCEL-POLICY-001 — an admin closes a disputed interim-work declaration, optionally correcting the
 * figures (after reviewing the photos and the status history). Re-opens cancellation for the customer.
 */
class ResolveInterimDisputeAction
{
    /** @throws \RuntimeException */
    public function execute(int $bookingId, User $admin, string $resolution, ?float $amount = null): Booking
    {
        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new \RuntimeException('Record how you resolved the dispute.');
        }
        if ($amount !== null && $amount < 0) {
            throw new \RuntimeException('The amount cannot be negative.');
        }

        $booking = DB::transaction(function () use ($bookingId, $admin, $resolution, $amount) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->interim_dispute_status !== 'open') {
                throw new \RuntimeException('There is no open dispute on this booking.');
            }

            $before = ['amount' => $booking->interim_amount];

            if ($amount !== null) {
                $cap = app(\App\Services\Cancellation\InterimChargeCalculator::class)->capValue($booking);
                if ($cap === null || $amount > $cap) {
                    throw new \RuntimeException($cap === null ? 'The work-done cap is not configured.' : 'The corrected amount cannot exceed the cap of '.number_format($cap, 2).'.');
                }
                $booking->interim_amount = round($amount, 2);
            }
            $booking->interim_dispute_status = 'resolved';
            $booking->interim_dispute_note = trim(($booking->interim_dispute_note ?? '')."\n[Resolved by admin] {$resolution}");
            $booking->save();

            $booking->statusHistory()->create([
                'status' => $booking->status,
                'changed_by' => $admin->id,
                'note' => "Dispute resolved by admin: {$resolution} (amount {$before['amount']} -> {$booking->interim_amount})",
                'changed_at' => now(),
            ]);

            ActivityLogger::logModel($admin, $booking, 'interim dispute resolved', ['resolution' => $resolution, 'before' => $before]);

            return $booking->fresh();
        });

        StageNotifier::customer($booking, 'interim_resolved');

        return $booking;
    }
}
