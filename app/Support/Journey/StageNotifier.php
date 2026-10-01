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
 * is not sent five messages for one job; the live timeline carries the detail. A hold is the one
 * stage that can need the customer's attention, so it uses every configured channel.
 * Always guarded: a delivery failure is logged and can never roll back the transition.
 */
final class StageNotifier
{
    /** Events that use the full configured channel set instead of push + in-app only. */
    private const FULL_CHANNEL_EVENTS = ['on_hold'];

    public static function customer(Booking $booking, string $event): void
    {
        $customer = $booking->customer;
        if (! $customer) {
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
