<?php

namespace App\Providers;

use App\Contracts\FirebaseTokenVerifier;
use App\Contracts\NarrativeAiAdapter;
use App\Contracts\PaymentGateway;
use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Contracts\WhatsAppAdapter;
use App\Services\Auth\GoogleFirebaseTokenVerifier;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Franchise;
use App\Models\HotelReservation;
use App\Models\NotificationLog;
use App\Models\ParcelOrder;
use App\Models\PropertyReservation;
use App\Models\RentalReservation;
use App\Models\Review;
use App\Models\TaxiRide;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\Adapters\ArkeselSmsAdapter;
use App\Notifications\Adapters\FirebaseFcmPushAdapter;
use App\Notifications\Adapters\GatewayApiSmsAdapter;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Notifications\Adapters\LogWhatsAppAdapter;
use App\Notifications\Adapters\Msg91SmsAdapter;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Observers\BookingBundleObserver;
use App\Observers\BookingObserver;
use App\Observers\FranchiseObserver;
use App\Observers\HotelReservationObserver;
use App\Observers\ParcelOrderObserver;
use App\Observers\PropertyReservationObserver;
use App\Observers\RentalReservationObserver;
use App\Observers\ReviewObserver;
use App\Observers\TaxiRideObserver;
use App\Observers\UserObserver;
use App\Observers\ZoneObserver;
use App\Services\Ai\AnthropicNarrativeAiAdapter;
use App\Services\Ai\LogNarrativeAiAdapter;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // BD-8: real adapters now exist (ArkeselSmsAdapter, Msg91SmsAdapter,
        // GatewayApiSmsAdapter, FirebaseFcmPushAdapter) but none is
        // auto-selected -- config('services.sms.driver') / config('services.push.driver')
        // (SMS_DRIVER/PUSH_DRIVER env) decide, both defaulting to 'log' so
        // nothing changes in any environment that hasn't deliberately set
        // them + real credentials. Arkesel is the vendor CONFIRMED actually
        // configured in the real Glover 1.8.5 reference deployment (see
        // ArkeselSmsAdapter's docblock and KNOWN_RISKS_AND_DECISIONS.md
        // item 8) -- msg91/gatewayapi remain available as alternates, not
        // removed. Nothing above this binding (SmsChannel/PushChannel, the
        // Notification classes, OtpService) needs to change regardless of
        // which driver is selected.
        $this->app->bind(SmsAdapter::class, fn ($app) => match (config('services.sms.driver', 'log')) {
            'arkesel' => $app->make(ArkeselSmsAdapter::class),
            'msg91' => $app->make(Msg91SmsAdapter::class),
            'gatewayapi' => $app->make(GatewayApiSmsAdapter::class),
            default => $app->make(LogSmsAdapter::class),
        });
        $this->app->bind(PushAdapter::class, fn ($app) => match (config('services.push.driver', 'log')) {
            'fcm' => $app->make(FirebaseFcmPushAdapter::class),
            default => $app->make(LogPushAdapter::class),
        });

        // Daily Digest session — same shape as SmsAdapter/PushAdapter above.
        // Unlike those, no real WhatsApp adapter has ever existed in this
        // codebase, so there is only ever the log default today; the match
        // is still config-driven (not a bare bind()) so a real driver slots
        // in later exactly like arkesel/msg91/gatewayapi did for SMS,
        // without anything above this binding changing.
        $this->app->bind(WhatsAppAdapter::class, fn ($app) => match (config('services.whatsapp.driver', 'log')) {
            default => $app->make(LogWhatsAppAdapter::class),
        });

        // Admin Polish + AI session — identical shape to SmsAdapter/PushAdapter
        // above: config('services.ai.driver') (AI_DRIVER env), defaulting to
        // 'log', so every environment that hasn't deliberately set a real
        // ANTHROPIC_API_KEY gets the null-returning fallback and every
        // caller of NarrativeAiAdapter (DailyInsightsService,
        // BookingNaturalLanguageFilter) already handles that null by
        // displaying its own real, non-AI data — see LogNarrativeAiAdapter's
        // own docblock. No real key exists anywhere in this codebase today.
        $this->app->bind(NarrativeAiAdapter::class, fn ($app) => match (config('services.ai.driver', 'log')) {
            'anthropic' => $app->make(AnthropicNarrativeAiAdapter::class),
            default => $app->make(LogNarrativeAiAdapter::class),
        });

        // Payment Gateway Manager session -- Razorpay is still the real,
        // already-selected provider, but the binding is now manager-
        // resolved rather than hardcoded, so an admin-activated
        // payment_gateways DB row (see App\Livewire\PaymentGateways\Manage)
        // takes over automatically. Every consumer (PaymentController,
        // WalletTopUpService, SubscriptionService, CancellationService)
        // still depends on PaymentGateway, not a concrete driver, so
        // NOTHING about any of them changes here -- see
        // PaymentGatewayManager's own docblock for the fallback guarantee
        // that keeps today's env-config behaviour identical until an admin
        // actually configures something.
        $this->app->singleton(PaymentGatewayManager::class);
        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(PaymentGatewayManager::class)->active());

        // Auth rebuild — server-side Firebase ID token verification (phone
        // OTP + Google sign-in). Real implementation talks to Google's
        // published signing certs; tests bind a fake over this contract
        // exactly like CapturingSmsAdapter does for SmsAdapter.
        $this->app->singleton(
            FirebaseTokenVerifier::class,
            fn ($app) => new GoogleFirebaseTokenVerifier(config('services.firebase.project_id')),
        );
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

        // Production-hardening session, Part 3 — bootstrap/app.php already
        // registers Laravel's own default health route (`health: '/up'`),
        // but out of the box that route only proves the app itself booted;
        // it dispatches this DiagnosingHealth event and reports "down"
        // (HTTP 500) only if a LISTENER throws. Nothing previously listened
        // for it, so a dead database or unreachable queue connection would
        // still report "up" -- exactly the "app process is alive but can't
        // actually do anything" false-positive a load balancer/uptime
        // monitor's health check exists to catch. Two lightweight,
        // side-effect-free checks: a real PDO connection (fails fast on a
        // dead DB) and Queue::size() (touches the actual queue backend --
        // for the 'database' driver in use here, a COUNT query against the
        // jobs table). Never returns/logs the exception message itself to
        // the caller (the framework's own health route already only ever
        // responds with {"status":"up"|"down"} — no stack trace, no
        // connection string, nothing sensitive), only reports it via
        // Laravel's own report() the framework route already calls.
        Event::listen(function (\Illuminate\Foundation\Events\DiagnosingHealth $event) {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            \Illuminate\Support\Facades\Queue::connection()->size();
        });

        Booking::observe(BookingObserver::class);
        BookingBundle::observe(BookingBundleObserver::class);
        ParcelOrder::observe(ParcelOrderObserver::class);
        TaxiRide::observe(TaxiRideObserver::class);
        PropertyReservation::observe(PropertyReservationObserver::class);
        RentalReservation::observe(RentalReservationObserver::class);
        HotelReservation::observe(HotelReservationObserver::class);
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
        //
        // On-demand routed sends (Notification::route(...)->notify(...)) have
        // an AnonymousNotifiable with no key -- currently only the email-OTP
        // in OtpService, sent before any User row exists. It gets no
        // notification_logs row (that table is the user-facing comms audit
        // trail, scoped by notifiable in the admin panel; a null-keyed row
        // has nothing to scope by and would break its NOT NULL notifiable_id).
        // A grep-able app-log line is the cheap stand-in until OTP
        // deliverability warrants a dedicated mechanism (Phase 2: a light
        // delivery-status table or a provider webhook).
        Event::listen(function (NotificationSent $event) {
            if ($event->notifiable instanceof AnonymousNotifiable) {
                Log::info('Anonymous notification sent', [
                    'notification' => get_class($event->notification),
                    'channel' => $this->normalizeChannelName($event->channel),
                    'route' => $event->notifiable->routes ?? [],
                    'event' => method_exists($event->notification, 'eventKey') ? $event->notification->eventKey() : null,
                ]);

                return;
            }

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
            if ($event->notifiable instanceof AnonymousNotifiable) {
                Log::warning('Anonymous notification failed', [
                    'notification' => get_class($event->notification),
                    'channel' => $this->normalizeChannelName($event->channel),
                    'route' => $event->notifiable->routes ?? [],
                    'event' => method_exists($event->notification, 'eventKey') ? $event->notification->eventKey() : null,
                    'error' => is_array($event->data ?? null) ? json_encode($event->data) : null,
                ]);

                return;
            }

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
            WhatsAppChannel::class => 'whatsapp',
            'database' => 'in_app',
            default => is_string($channel) ? $channel : class_basename($channel),
        };
    }
}