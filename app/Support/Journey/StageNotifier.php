<?php

namespace App\Support\Journey;

use App\Models\Booking;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-JOURNEY-001 — tells the CUSTOMER about the middle of the job journey: provider on the
 * way, work started, job on hold, spares available, work resumed. (Created / assigned / completed /
 * cancelled already notified; these in-between stages used to be silent for the customer.)
 *
 * Channel policy: the minor stages go only to push and in-app — never e-mail or SMS — so a customer
 * is not sent five messages for one job; the live timeline carries the detail. The stages that can need the
 * customer's attention (a hold, an extra-work request, a new professional taking over) use every configured channel.
 * Always guarded: a delivery failure is logged and can never roll back the transition.
 */
final class StageNotifier
{
    /** Events that use the full configured channel set instead of push + in-app only. */
    private const FULL_CHANNEL_EVENTS = [
        'on_hold', 'extra_work_proposed', 'reassigned',
        // REF 1CF-CANCEL-POLICY-001 — anything that changes what the customer can do or owes
        'spares_early_unlock', 'spares_delay_warning', 'spares_cancel_unlocked', 'spares_date_passed',
        'spares_resume_overdue', 'extra_work_expired', 'cancel_payment_due', 'interim_resolved',
    ];

    public static function customer(Booking $booking, string $event): void
    {
        $customer = $booking->customer;
        if (! $customer) {
            return;
        }

        // The extra-work request sends its own, more specific message right after the hold.
        if ($event === 'on_hold' && $booking->hold_reason === 'awaiting_customer_approval') {
            return;
        }

        try {
            $scope = array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $channels = in_array($event, self::FULL_CHANNEL_EVENTS, true)
                ? ChannelResolver::resolve($scope)
                : array_values(array_intersect(ChannelResolver::resolve($scope), [PushChannel::class, 'database']));

            if ($channels === []) {
                return;
            }

            $customer->notify(new BookingStatusNotification($event, $booking, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver customer '{$event}' stage notification for booking [{$booking->id}]: ".$e->getMessage());
        }
    }
}
