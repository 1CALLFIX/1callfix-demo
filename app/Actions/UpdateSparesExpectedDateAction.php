<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\Provider;
use App\Services\Cancellation\SparesDelayClock;
use App\Support\Journey\StageNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REF 1CF-CANCEL-POLICY-001 — the professional gives a new expected arrival date for the spare part (asked for when
 * the old date passes). A date further away than the threshold opens cancellation for the customer immediately.
 */
class UpdateSparesExpectedDateAction
{
    /** @throws \RuntimeException */
    public function execute(int $bookingId, Provider $provider, string $date): Booking
    {
        try {
            $expected = Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw new \RuntimeException('Enter a valid date.');
        }
        if ($expected->lt(now()->startOfDay())) {
            throw new \RuntimeException('The new date cannot be in the past.');
        }

        $unlocked = false;

        $booking = DB::transaction(function () use ($bookingId, $provider, $expected, &$unlocked) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $provider->id) {
                throw new \RuntimeException('This booking is not assigned to you.');
            }
            if ($booking->status !== 'on_hold' || $booking->hold_reason !== 'awaiting_spares') {
                throw new \RuntimeException('The job is not waiting for spare parts.');
            }

            $booking->spares_expected_at = $expected;
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'on_hold',
                'changed_by' => $provider->user_id,
                'note' => 'Expected spare-part date updated to '.$expected->format('j M Y'),
                'changed_at' => now(),
            ]);

            $unlocked = app(SparesDelayClock::class)->earlyUnlocked($booking);

            return $booking->fresh();
        });

        StageNotifier::customer($booking, $unlocked ? 'spares_early_unlock' : 'spares_declared');

        return $booking;
    }
}
