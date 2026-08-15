<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Models\Booking;
use App\Models\Franchise;
use App\Models\NotificationLog;
use App\Models\Review;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\SmsChannel;
use App\Observers\BookingObserver;
use App\Observers\FranchiseObserver;
use App\Observers\ReviewObserver;
use App\Observers\UserObserver;
use App\Observers\ZoneObserver;
use App\Services\RazorpayService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Razorpay IS the real, already-selected provider (unlike SMS/push
        // above) -- this binding is the abstraction boundary, not a stand-in:
        // every consumer (PaymentController, WalletTopUpService,
        // SubscriptionService, CancellationService) now depends on
        // PaymentGateway, not RazorpayService directly, so a second provider
        // is a new bound class here, not a change to any of them.
        $this->app->bind(PaymentGateway::class, RazorpayService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mission Phase 16 (API/security/E2E hardening sweep) finding:
        // routes/api.php had NO general-purpose rate limiter at all --
        // bootstrap/app.php never called $middleware->throttleApi(), and
        // nothing anywhere registered a limiter named 'api'. Only the 6
        // auth/OTP/QR routes were throttled (their own explicit per-route
        // throttle:X,1). Every other authenticated route -- wallet top-up,
        // loyalty redeem, payment order creation, chat, reviews, tips,
        // subscriptions -- could be called at unlimited request volume by
        // any valid Sanctum token. Laravel's own standard "api" starter-kit
        // default (60/min, keyed per authenticated user or per IP for
        // guests) is applied here rather than an invented number -- an
        // honest engineering default, not a business decision. Wired onto
        // the actual 'api' route group via $middleware->throttleApi() in
        // bootstrap/app.php.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Booking::observe(BookingObserver::class);
        Franchise::observe(FranchiseObserver::class);
        Zone::observe(ZoneObserver::class);
        Review::observe(ReviewObserver::class);
        User::observe(UserObserver::class);

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