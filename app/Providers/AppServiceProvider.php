<?php

namespace App\Providers;

use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Models\Booking;
use App\Models\Franchise;
use App\Models\NotificationLog;
use App\Models\Zone;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Observers\BookingObserver;
use App\Observers\FranchiseObserver;
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

        // One listener records every notification attempt (any channel: the
        // built-in 'mail', or our custom SmsChannel/PushChannel) into
        // notification_logs -- the real audit trail behind the Notifications
        // Settings tab, rather than each channel/adapter logging it itself.
        Event::listen(function (NotificationSent $event) {
            NotificationLog::create([
                'notifiable_type' => get_class($event->notifiable),
                'notifiable_id' => $event->notifiable->getKey(),
                'channel' => is_string($event->channel) ? $event->channel : class_basename($event->channel),
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
                'channel' => is_string($event->channel) ? $event->channel : class_basename($event->channel),
                'notification_type' => get_class($event->notification),
                'event' => method_exists($event->notification, 'eventKey') ? $event->notification->eventKey() : null,
                'status' => 'failed',
                'error' => is_array($event->data ?? null) ? json_encode($event->data) : null,
                'sent_at' => now(),
            ]);
        });
    }
}