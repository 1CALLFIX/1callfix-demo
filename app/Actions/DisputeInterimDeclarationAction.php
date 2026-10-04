<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\Setting;
use App\Services\AdminOpsAlertService;
use App\Services\Cancellation\SparesDelayClock;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-CANCEL-POLICY-001 — the customer disputes the progress / parts the professional declared when holding the
 * job for spares. Freezes the figures and blocks cancellation until an admin resolves it
 * (ResolveInterimDisputeAction). Allowed only inside the dispute window after the declaration.
 */
class DisputeInterimDeclarationAction
{
    public const DEFAULT_WINDOW_HOURS = 48;

    /** @throws \RuntimeException */
    public function execute(int $bookingId, int $customerId, string $note): Booking
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw new \RuntimeException('Tell us briefly what looks wrong (up to 1000 characters).');
        }

        $booking = DB::transaction(function () use ($bookingId, $customerId, $note) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->customer_id !== $customerId) {
                throw new \RuntimeException('This is not your booking.');
            }
            if ($booking->status !== 'on_hold' || $booking->hold_reason !== 'awaiting_spares' || $booking->interim_declared_at === null) {
                throw new \RuntimeException('There are no declared progress figures to dispute right now.');
            }
            if ($booking->interim_dispute_status === 'open') {
                throw new \RuntimeException('You have already disputed these figures. Our team is reviewing them.');
            }

            $hours = max(1, (int) Setting::get('cancellation.dispute_window_hours', (string) self::DEFAULT_WINDOW_HOURS, app(SparesDelayClock::class)->scopeFor($booking)));
            if ($booking->interim_declared_at->copy()->addHours($hours)->isPast()) {
                throw new \RuntimeException("The {$hours}-hour window to dispute these figures has passed. Contact support if you still have a concern.");
            }

            $booking->interim_dispute_status = 'open';
            $booking->interim_disputed_at = now();
            $booking->interim_dispute_note = $note;
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'on_hold',
                'changed_by' => $customerId,
                'note' => "Customer disputed the declared amount ({$booking->interim_amount}): {$note}",
                'changed_at' => now(),
            ]);

            return $booking->fresh();
        });

        try {
            app(AdminOpsAlertService::class)->cancellationEvent('interim_dispute', $booking);
            if ($booking->provider?->user) {
                $channels = ChannelResolver::resolve(array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]));
                $booking->provider->user->notify(new ProviderJobStatusNotification('spares_dispute', $booking, $channels));
            }
        } catch (\Throwable $e) {
            Log::error("Dispute notifications failed for booking [{$booking->id}]: ".$e->getMessage());
        }

        return $booking;
    }
}
