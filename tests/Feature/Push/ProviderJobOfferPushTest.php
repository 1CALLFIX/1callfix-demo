<?php

namespace Tests\Feature\Push;

use App\Contracts\PushAdapter;
use App\Jobs\ServiceMatchingJob;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ProviderJobOfferNotification;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 2 — ServiceMatchingJob now also pushes the offer to every
 * candidate provider (the app-closed case the NewJobOffered WebSocket
 * broadcast cannot reach). The 6 existing status notifications already
 * route through push via ChannelResolver once a token exists and need no
 * change; the OFFER is the genuinely new send site.
 */
class ProviderJobOfferPushTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
    }

    private function makeOnlineProvider($franchise, $zone): Provider
    {
        $categoryId = Service::first()->category_id;
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider '.Str::random(4),
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
            'fcm_token' => 'provider-web-token',
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => 1.0, 'current_lng' => 1.0, 'skills' => [$categoryId],
        ]);
    }

    public function test_matching_job_pushes_the_offer_to_a_candidate_provider(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('pending');
        $provider = $this->makeOnlineProvider($franchise, $zone);

        (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));

        // The offer really went out (dispatch_attempts row) AND a push-bound
        // offer notification was sent to the provider's User.
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'notified',
        ]);

        Notification::assertSentTo(
            $provider->user,
            ProviderJobOfferNotification::class,
            function (ProviderJobOfferNotification $n) use ($provider) {
                return in_array(PushChannel::class, $n->via($provider->user), true);
            }
        );
    }

    public function test_offer_notification_deep_links_to_the_job(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');

        $notification = new ProviderJobOfferNotification($booking, [PushChannel::class]);

        $this->assertSame(
            route('provider.jobs.show', $booking),
            $notification->pushLink($provider->user)
        );
    }

    public function test_the_push_adapter_is_never_hit_for_a_provider_without_a_token(): void
    {
        // PushChannel::send() no-ops (before touching the adapter) when
        // routeNotificationForPush() is null — the offer notification is
        // still "sent", it just resolves to nothing on the push channel.
        $calls = [];
        $this->app->bind(PushAdapter::class, function () use (&$calls) {
            return new class($calls) implements PushAdapter {
                public function __construct(public array &$calls) {}
                public function send(string $token, string $title, string $body, array $data = []): bool
                {
                    $this->calls[] = $token;

                    return true;
                }
            };
        });

        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('pending');
        $provider = $this->makeOnlineProvider($franchise, $zone);
        $provider->user->update(['fcm_token' => null]);

        (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));

        $this->assertSame([], $calls, 'PushChannel must not call the adapter when the provider has no fcm_token.');
    }
}
