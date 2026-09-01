<?php

namespace App\Livewire\Provider\Concerns;

use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * PHASE PW1 §9 — provider-facing stuck-accepted-job detection. Read-only,
 * and it reuses the SAME thresholds and the SAME "when did it enter this
 * status" logic as App\Services\Operations\StuckBookingService
 * (operations.stuck_threshold_minutes.{status} settings, latest
 * booking_status_history row, created_at fallback) — no new engine, no new
 * settings keys, no mutation.
 */
trait DetectsStuckJob
{
    /** Minutes the booking has been in its current status past the configured threshold, or null if not stuck / not a watched status. */
    protected function stuckMinutes(Booking $booking): ?int
    {
        $defaults = ['assigned' => 60, 'in_progress' => 240];

        if (! array_key_exists($booking->status, $defaults)) {
            return null;
        }

        $threshold = (int) Setting::get(
            "operations.stuck_threshold_minutes.{$booking->status}",
            (string) $defaults[$booking->status]
        );

        $enteredAt = BookingStatusHistory::where('booking_id', $booking->id)
            ->where('status', $booking->status)
            ->latest('changed_at')
            ->value('changed_at') ?? $booking->created_at;

        $minutes = (int) Carbon::parse($enteredAt)->diffInMinutes(now());

        return $minutes >= $threshold ? $minutes : null;
    }
}
