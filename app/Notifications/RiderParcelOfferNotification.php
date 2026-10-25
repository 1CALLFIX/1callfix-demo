<?php

namespace App\Notifications;

use App\Models\ParcelOrder;
use App\Notifications\Channels\PushChannel;
use App\Support\DispatchQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Closed-app push to a rider offered a parcel (the ProviderJobOfferNotification counterpart). Push only: an
 * offer lives for seconds, so mail/SMS would arrive after it expired. A rider with no registered web-push
 * token simply gets nothing here and relies on the rider page's own ring.
 */
class RiderParcelOfferNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private ParcelOrder $order)
    {
    }

    public function via($notifiable): array
    {
        return [PushChannel::class];
    }

    /** The offer push rides the offer queue (DispatchQueue). Null (not configured) = unchanged. */
    public function viaQueues(): array
    {
        return [PushChannel::class => DispatchQueue::name()];
    }

    public function eventKey(): string
    {
        return 'rider.parcel_offered';
    }

    public function pushLink($notifiable): string
    {
        return route('rider.dashboard');
    }

    public function toPush($notifiable): array
    {
        $distance = $this->order->distance_km !== null ? ' · '.rtrim(rtrim(number_format((float) $this->order->distance_km, 1), '0'), '.').' km' : '';

        return [
            'title' => 'New parcel offer',
            'body' => "Parcel {$this->order->code} · ₹".number_format((float) $this->order->price_quoted, 2)."{$distance}. Open the rider page to accept before it expires.",
        ];
    }
}
