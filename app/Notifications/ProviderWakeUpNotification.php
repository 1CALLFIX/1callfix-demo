<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Channels\PushChannel;
use App\Support\DispatchQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "New AC repair job in Kothapet. Open the app and go online to take it." Sent once per job to offline providers
 * when nobody online could take it (App\Services\Providers\OfflineWakeUp). Push only; rings on the Partner app's loud
 * offers channel and opens the partner home, where the big Go online switch is. Not an offer: the job is offered
 * the normal way once the provider is online.
 */
class ProviderWakeUpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Booking $booking, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function viaQueues(): array
    {
        return [PushChannel::class => DispatchQueue::name()];
    }

    public function eventKey(): string
    {
        return 'provider.wake_offline';
    }

    public function pushApp(): string
    {
        return 'partner';
    }

    /** The Android apps ring kind=offer pushes on the loud offers channel. */
    public function pushKind(): string
    {
        return 'offer';
    }

    public function pushLink($notifiable): string
    {
        return route('provider.dashboard');
    }

    public function toPush($notifiable): array
    {
        $service = $this->booking->service?->name ?? 'A';
        $area = $this->booking->zone?->name;

        return [
            'title' => '🔔 New job near you',
            'body' => trim("{$service} job".($area ? " in {$area}" : '')).'. Open the app and go online to take it.',
        ];
    }
}
