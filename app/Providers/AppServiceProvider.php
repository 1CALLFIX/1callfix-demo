<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Models\Booking;
use App\Models\Franchise;
use App\Models\NotificationLog;
use App\Models\ParcelOrder;
use App\Models\Review;
use App\Models\TaxiRide;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\Adapters\FirebaseFcmPushAdapter;
use App\Notifications\Adapters\GatewayApiSmsAdapter;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Notifications\Adapters\Msg91SmsAdapter;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\SmsChannel;
use App\Observers\BookingObserver;
use App\Observers\FranchiseObserver;
use App\Observers\ParcelOrderObserver;
use App\Observers\ReviewObserver;
use App\Observers\TaxiRideObserver;
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
        // BD-8: real adapters now exist (Msg91SmsAdapter, GatewayApiSmsAdapter,
        // FirebaseFcmPushAdapter) but none is auto-selected -- config('services.sms.driver')
        // / config('services.push.driver') (SMS_DRIVER/PUSH_DRIVER env) decide, both
        // defaulting to 'log' so nothing changes in any environment that
        // hasn't deliberately set them + real credentials. See
        // KNOWN_RISKS_AND_DECISIONS.md item 8 for why the vendor choice
        // itself is not made here. Nothing above this binding (SmsChannel/
        // PushChannel, the Notification classes, OtpService) needs to
        // change regardless of which driver is selected.
        $this->app->bind(SmsAdapter::class, fn ($app) => match (config('services.sms.driver', 'log')) {
            'msg91' => $app->make(Msg91SmsAdapter::class),
            'gatewayapi' => $app->make(GatewayApiSmsAdapter::class),
            default => $app->make(LogSmsAdapter::class),
        });
        $this->app->bind(PushAdapter::class, fn ($app) => match (config('services.push.driver', 'log')) {
            'fcm' => $app->make(FirebaseFcmPushAdapter::class),
            default => $app->make(LogPushAdapter::class),
        });

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
        ParcelOrder::observe(ParcelOrderObserver::class);
        TaxiRide::observe(TaxiRideObserver::class);
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