<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Support\Journey\JourneyBuilder;
use Illuminate\Support\Facades\DB;

/**
 * REF 1CF-JOURNEY-001 — the "Spare Available" step of a job held for spares:
 *
 *   In progress -> On hold (awaiting spares) -> Spares available -> Resume -> In progress
 *
 * The booking stays `on_hold` (no state-machine change, no migration); the step is recorded as
 * a status-history entry the journey timeline reads. Idempotent: a second call during the same
 * hold does not write a duplicate entry.
 */
class MarkSparesAvailableAction
{
    /** @throws \RuntimeException if the job is not on hold for spare parts */
    public function execute(int $bookingId, ?int $changedBy = null, ?string $note = null): Booking
    {
        $already = false;

        $booking = DB::transaction(function () use ($bookingId, $changedBy, $note, &$already) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== 'on_hold' || $booking->hold_reason !== 'awaiting_spares') {
                throw new \RuntimeException("Booking [{$bookingId}] is not on hold waiting for spare parts.");
            }

            $exists = $booking->statusHistory()
                ->where('status', 'on_hold')
                ->where('note', 'like', JourneyBuilder::SPARES_NOTE.'%')
                ->when($booking->on_hold_since, fn ($q) => $q->where('changed_at', '>=', $booking->on_hold_since))
                ->exists();

            if ($exists) {
                $already = true;

                return $booking;
            }

            $booking->statusHistory()->create([
                'status' => 'on_hold',
                'changed_by' => $changedBy ?? $booking->provider?->user_id,
                'note' => JourneyBuilder::SPARES_NOTE.($note ? " — {$note}" : ''),
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        if (! $already) {
            \App\Support\Journey\StageNotifier::customer($booking, 'spares_available');
        }

        return $booking;
    }
}
