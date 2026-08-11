<?php

namespace App\Providers;

use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Models\Booking;
use App\Models\Franchise;
use App\Models\NotificationLog;
use App\Models\Review;
use App\Models\Zone;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\SmsChannel;
use App\Observers\BookingObserver;
use App\Observers\FranchiseObserver;
use App\Observers\ReviewObserver;
use App\Observers\ZoneObserver;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // No real SMS/push provider is configured anywhere in this codebase
        // (confirmed by audit) -- bind the log-based fake adapters so the
        // full event -> channel -> adapter flow is real and testable today.
        // Swap these two bindings for real provider adapters later; nothing
        // else (channels, Notification classes, call sites) needs to change.
        $this->app->bind(SmsAdapter::class, LogSmsAdapter::class);
        $this->app->bind(PushAdapter::class, LogPushAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Booking::observe(BookingObserver::class);
        Franchise::observe(FranchiseObserver::class);
        Zone::observe(ZoneObserver::class);
        Review::observe(ReviewObserver::class);

        // One listener records every notification attempt (any channel: the
        // built-in 'mail', or our custom SmsChannel/PushChannel) into
        // notification_logs -- the real audit trail behind the Notifications
        // Settings tab, rather than each channel/adapter logging it itself.
        // Laravel hands the channel back as either the literal string 'mail'
        // or the FQCN of a custom channel class (SmsChannel::class) -- both
        // via() and this listener need to agree on that shape, so it's
        // normalized to a short code here for a readable audit trail.
        Event::listen(function (NotificationSent $event) {
            NotificationLog::create([
                'notifiable_type' => get_class($event->notifiable),
                'notifiable_id' => $event->notifiable->getKey(),
                'campaign_id' => method_exists($event->notification, 'campaignId') ? $event->notification->campaignId() : null,
                'channel' => $this->normalizeChannelName($event->channel),
                'notification_type' => get_class($event->notification),
                'event' => method_exists($event->notification, 'eventKey') ? $event->notification->eventKey() : null,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        });

        Event::listen(function (NotificationFailed $event) {
            NotificationLog::create([
                'notifiable_type' => get_class($event->notifiable),
                'notifiable_id' => $event->notifiable->getKey(),
                'campaign_id' => method_exists($event->notification, 'campaignId') ? $event->notification->campaignId() : null,
                'channel' => $this->normalizeChannelName($event->channel),
                'notification_type' => get_class($event->notification),
                'event' => method_exists($event->notification, 'eventKey') ? $event->notification->eventKey() : null,
                'status' => 'failed',
                'error' => is_array($event->data ?? null) ? json_encode($event->data) : null,
                'sent_at' => now(),
            ]);
        });
    }

    private function normalizeChannelName(mixed $channel): string
    {
        return match ($channel) {
            SmsChannel::class => 'sms',
            PushChannel::class => 'push',
            'database' => 'in_app',
            default => is_string($channel) ? $channel : class_basename($channel),
        };
    }
}