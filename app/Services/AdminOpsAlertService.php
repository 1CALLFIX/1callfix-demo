<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\Log;

/**
 * Fans a single operational event out to every admin who opted in to
 * order alerts (users.push_ops_alerts = true AND has an fcm_token). One
 * place so the two hook sites — booking creation and Razorpay capture —
 * stay one line each and can't drift.
 *
 * Best-effort: a failure here must never roll back a booking or a
 * payment-capture webhook, so every send is guarded.
 */
class AdminOpsAlertService
{
    public function bookingCreated(Booking $booking): void
    {
        $this->fanOut('booking_created', $booking);
    }

    public function paymentCaptured(Payment $payment): void
    {
        $this->fanOut('payment_captured', $payment);
    }

    private function fanOut(string $event, Booking|Payment $subject): void
    {
        // Push ONLY — this is an operational nudge, not an inbox item, and
        // AdminOpsAlertNotification deliberately has no toMail(). Still
        // gated on the global notifications.channels setting: intersecting
        // with the resolved list means turning push off platform-wide
        // silences these too, rather than bypassing the setting.
        $channels = array_values(array_intersect(ChannelResolver::resolve([]), [PushChannel::class]));

        if (empty($channels)) {
            return;
        }

        User::query()
            ->where('push_ops_alerts', true)
            ->whereNotNull('fcm_token')
            ->chunkById(200, function ($admins) use ($event, $subject, $channels) {
                foreach ($admins as $admin) {
                    try {
                        $admin->notify(new AdminOpsAlertNotification($event, $subject, $channels));
                    } catch (\Throwable $e) {
                        Log::warning('AdminOpsAlertService: failed to queue alert.', [
                            'event' => $event,
                            'admin_id' => $admin->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
