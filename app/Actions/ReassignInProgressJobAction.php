<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BookingOtpNotification;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\ActivityLogger;
use App\Services\BookingOtpService;
use App\Support\Journey\StageNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-JOURNEY-001 — hands a job whose professional LEFT mid-work (a provider-side hold) to another
 * professional so the customer's work gets finished.
 *
 *   in_progress -> on_hold (provider-side, "professional left")  ->  assigned (new professional)
 *      -> on the way -> started (fresh start OTP) -> completed
 *
 * Money: nothing moves here. Commission and earnings are created once, at completion, for whoever
 * completes the job — the professional who left earns nothing for it. The completion code the customer
 * already holds is kept (its expiry is refreshed); only a NEW start code is issued, and the customer
 * is sent it.
 */
class ReassignInProgressJobAction
{
    /**
     * @throws \RuntimeException if the job is not on a provider-side hold, or the new professional is not eligible
     */
    public function execute(int $bookingId, int $newProviderId, ?int $adminUserId = null, ?string $note = null): Booking
    {
        $previousProviderId = null;
        $newStartOtp = null;

        $booking = DB::transaction(function () use ($bookingId, $newProviderId, $adminUserId, $note, &$previousProviderId, &$newStartOtp) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);
            $new = Provider::findOrFail($newProviderId);

            if ($booking->status !== 'on_hold' || $booking->hold_category !== 'provider_side') {
                throw new \RuntimeException('Only a job flagged because the professional left can be handed to someone else.');
            }
            if ($new->id === $booking->provider_id) {
                throw new \RuntimeException('Pick a different professional to continue the job.');
            }
            if ($new->kyc_status !== 'approved' || ! $new->is_active) {
                throw new \RuntimeException('That professional is not eligible to take jobs.');
            }

            $previousProviderId = $booking->provider_id;
            $otp = app(BookingOtpService::class);

            $length = (int) Setting::get('booking.otp_length', 4);
            $newStartOtp = (string) random_int((int) (10 ** ($length - 1)), (int) (10 ** $length) - 1);

            $booking->provider_id = $new->id;
            $booking->assigned_worker_id = null;
            $booking->status = 'assigned';
            $booking->hold_category = null;
            $booking->hold_reason = null;
            $booking->hold_note = null;
            $booking->on_hold_since = null;
            $booking->start_otp = $newStartOtp;
            if (! $booking->completion_otp) {
                $booking->completion_otp = (string) random_int((int) (10 ** ($length - 1)), (int) (10 ** $length) - 1);
            }
            $otp->stampFresh($booking); // fresh expiry / attempts for both codes; the completion code itself is kept
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'assigned',
                'changed_by' => $adminUserId,
                'note' => "Job reassigned after the previous professional left (#{$previousProviderId} → #{$new->id})".(trim((string) $note) !== '' ? ' — '.trim($note) : ''),
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh(['customer', 'provider.user']);
        });

        $this->afterCommit($booking, $previousProviderId, $newStartOtp, $adminUserId);

        return $booking;
    }

    private function afterCommit(Booking $booking, ?int $previousProviderId, ?string $startOtp, ?int $adminUserId): void
    {
        $scope = array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

        try {
            ActivityLogger::logModel($adminUserId ? User::find($adminUserId) : null, $booking, 'job reassigned after provider left', [
                'previous_provider_id' => $previousProviderId, 'new_provider_id' => $booking->provider_id,
            ]);

            StageNotifier::customer($booking, 'reassigned');

            if ($booking->customer && $startOtp) {
                $booking->customer->notify(new BookingOtpNotification($booking, 'start', $startOtp, ChannelResolver::resolve($scope)));
            }

            $booking->provider?->user?->notify(new ProviderJobStatusNotification('reassigned_to_you', $booking, ChannelResolver::resolve($scope)));

            $previous = $previousProviderId ? Provider::with('user')->find($previousProviderId) : null;
            $previous?->user?->notify(new ProviderJobStatusNotification('reassigned_away', $booking, ChannelResolver::resolve($scope)));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver reassignment notifications for booking [{$booking->id}]: ".$e->getMessage());
        }
    }
}
