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
    public function execute(int $bookingId, User $admin, string $resolution, ?int $progressPercent = null, ?float $partsCost = null): Booking
    {
        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new \RuntimeException('Record how you resolved the dispute.');
        }
        if ($progressPercent !== null && ($progressPercent < 0 || $progressPercent > 100)) {
            throw new \RuntimeException('Progress must be between 0 and 100.');
        }
        if ($partsCost !== null && $partsCost < 0) {
            throw new \RuntimeException('Parts cost cannot be negative.');
        }

        $booking = DB::transaction(function () use ($bookingId, $admin, $resolution, $progressPercent, $partsCost) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->interim_dispute_status !== 'open') {
                throw new \RuntimeException('There is no open dispute on this booking.');
            }

            $before = ['progress' => $booking->interim_progress_percent, 'parts' => $booking->interim_parts_cost];

            if ($progressPercent !== null) {
                $booking->interim_progress_percent = $progressPercent;
            }
            if ($partsCost !== null) {
                $booking->interim_parts_cost = round($partsCost, 2);
            }
            $booking->interim_dispute_status = 'resolved';
            $booking->interim_dispute_note = trim(($booking->interim_dispute_note ?? '')."\n[Resolved by admin] {$resolution}");
            $booking->save();

            $booking->statusHistory()->create([
                'status' => $booking->status,
                'changed_by' => $admin->id,
                'note' => "Dispute resolved by admin: {$resolution} (progress {$before['progress']}% -> {$booking->interim_progress_percent}%, parts {$before['parts']} -> {$booking->interim_parts_cost})",
                'changed_at' => now(),
            ]);

            ActivityLogger::logModel($admin, $booking, 'interim dispute resolved', ['resolution' => $resolution, 'before' => $before]);

            return $booking->fresh();
        });

        StageNotifier::customer($booking, 'interim_resolved');

        return $booking;
    }
}
