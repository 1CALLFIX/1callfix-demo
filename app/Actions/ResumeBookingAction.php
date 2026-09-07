<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResumeBookingAction
{
    /**
     * Resumes a held booking back to in_progress and clears the hold fields.
     * The hold history itself isn't lost — it's preserved in
     * booking_status_history, this just clears the "currently on hold" state.
     *
     * @throws \RuntimeException if the booking isn't currently on hold
     */
    public function execute(int $bookingId, ?string $resolutionNote = null): Booking
    {
        $booking = DB::transaction(function () use ($bookingId, $resolutionNote) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== 'on_hold') {
                throw new \RuntimeException("Booking [{$bookingId}] is not currently on hold.");
            }

            $previousReason = $booking->hold_reason;

            $booking->status = 'in_progress';
            $booking->hold_category = null;
            $booking->hold_reason = null;
            $booking->hold_note = null;
            $booking->on_hold_since = null;
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'in_progress',
                'changed_by' => $booking->provider?->user_id,
                'note' => "Resumed from hold (was: {$previousReason})" .
                    ($resolutionNote ? " — {$resolutionNote}" : ''),
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        // Phase PN1 — post-commit provider notification (job off hold).
        // Guarded + logged; cannot roll back the committed resume.
        $this->notifyProviderOfStatus($booking, 'resumed');

        return $booking;
    }

    private function notifyProviderOfStatus(Booking $booking, string $event): void
    {
        $user = $booking->provider?->user;

        if (! $user) {
            return;
        }

        $channels = ChannelResolver::resolve(array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
        ]));

        try {
            $user->notify(new ProviderJobStatusNotification($event, $booking, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver provider '{$event}' notification for booking [{$booking->id}]: ".$e->getMessage());
        }
    }
}
