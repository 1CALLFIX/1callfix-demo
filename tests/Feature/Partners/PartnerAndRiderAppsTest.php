<?php

namespace Tests\Feature\Partners;

use App\Exceptions\InvalidPushTokenException;
use App\Models\Booking;
use App\Models\ParcelOrder;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserPushToken;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ProviderJobOfferNotification;
use App\Notifications\RiderParcelOfferNotification;
use App\Services\DispatchService;
use App\Support\PushApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner rule 2026-11-06: three apps, one account. Deliveries (parcel, food, shop) live in the Rider app; services,
 * shops and restaurants in the Partner app. One person may use both on one phone, so each app keeps its own push
 * token and rings only for its own work; a button for the other role opens the other app; a page of one app opened
 * in the other says "open the other app"; and while a service job is in progress, no delivery offers come.
 */
class PartnerAndRiderAppsTest extends TestCase
{
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    private const RIDER_UA = 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile 1CallFixApp/rider';
    private const PARTNER_UA = 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile 1CallFixApp/partner';

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active',
        ], $overrides));
    }

    /** One account holding both roles: an online parcel rider who is also an approved service partner. */
    private function plumberRider(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);
        $provider = Provider::create([
            'user_id' => $rider->user_id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
        ]);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider', 'provider');
    }

    private function parcelOrder(array $w): ParcelOrder
    {
        return app(\App\Actions\CreateParcelOrderAction::class)->execute([
            'restricted_items_confirmed' => true, 'payment_method' => 'cash',
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_name' => 'Asha', 'recipient_phone' => '9876543210',
        ]);
    }

    /** Would this rider be offered the order now? Earlier offers are cleared so only today's rules decide. */
    private function offered(ParcelOrder $order, int $riderId): bool
    {
        \App\Models\DispatchAttempt::query()->delete();

        return app(DispatchService::class)->findWorkerCandidates($order->fresh())->pluck('provider.id')->contains($riderId);
    }

    // ═════════════════════ each app keeps its own notifications ═════════════════════

    public function test_each_app_saves_its_own_token_on_one_account(): void
    {
        $user = $this->user(['fcm_token' => 'browser-token']);

        $this->actingAs($user)->withHeader('User-Agent', self::RIDER_UA)->postJson('/push/token', ['token' => 'rider-phone'])->assertOk();
        $this->actingAs($user)->withHeader('User-Agent', self::PARTNER_UA)->postJson('/push/token', ['token' => 'partner-phone'])->assertOk();

        $this->assertSame(['partner' => 'partner-phone', 'rider' => 'rider-phone'], $user->pushTokens()->orderBy('app')->pluck('token', 'app')->all());
        $this->assertSame('browser-token', $user->fresh()->fcm_token, 'the browser token is left alone');
    }

    public function test_a_plumbing_job_rings_on_the_partner_app_a_delivery_on_the_rider_app_and_a_booking_update_on_the_customer_app(): void
    {
        $user = $this->user();
        foreach (['partner' => 'p-token', 'rider' => 'r-token', 'customer' => 'c-token'] as $app => $token) {
            PushApp::register($user, $app, $token);
        }
        $booking = new Booking();
        $order = new ParcelOrder();

        $this->assertSame('p-token', $user->routeNotificationForPush(new ProviderJobOfferNotification($booking, ['push'])));
        $this->assertSame('r-token', $user->routeNotificationForPush(new RiderParcelOfferNotification($order)));
        $this->assertSame('c-token', $user->routeNotificationForPush(new BookingStatusNotification('assigned', $booking, ['push'])));
    }

    public function test_an_alert_never_rings_in_the_wrong_app_and_browsers_still_get_theirs(): void
    {
        // Only the Rider app is signed in: a plumbing offer is not sent to it.
        $riderOnly = $this->user();
        PushApp::register($riderOnly, 'rider', 'r-token');
        $this->assertNull($riderOnly->routeNotificationForPush(new ProviderJobOfferNotification(new Booking(), ['push'])));

        // A browser (web push) user with no app token keeps getting everything, as before.
        $browser = $this->user(['fcm_token' => 'browser-token']);
        $this->assertSame('browser-token', $browser->routeNotificationForPush(new ProviderJobOfferNotification(new Booking(), ['push'])));

        // Rider app plus a browser: the plumbing offer goes to the browser, never to the Rider app.
        PushApp::register($browser, 'rider', 'r2-token');
        $this->assertSame('browser-token', $browser->routeNotificationForPush(new ProviderJobOfferNotification(new Booking(), ['push'])));
    }

    public function test_an_old_single_token_from_an_app_moves_to_that_app_when_the_app_opens_again(): void
    {
        $user = $this->user(['fcm_token' => 'phone-token']);

        $this->actingAs($user)->withHeader('User-Agent', self::RIDER_UA)->postJson('/push/token', ['token' => 'phone-token'])->assertOk();

        $this->assertNull($user->fresh()->fcm_token);
        $this->assertSame('phone-token', $user->pushTokens()->where('app', 'rider')->value('token'));
        $this->assertNull($user->fresh()->routeNotificationForPush(new ProviderJobOfferNotification(new Booking(), ['push'])));
    }

    public function test_someone_else_signing_in_on_the_same_app_takes_the_phone_over(): void
    {
        $first = $this->user();
        $second = $this->user();
        PushApp::register($first, 'rider', 'shared-phone');

        $this->actingAs($second)->withHeader('User-Agent', self::RIDER_UA)->postJson('/push/token', ['token' => 'shared-phone'])->assertOk();

        $this->assertSame(0, $first->pushTokens()->count());
        $this->assertSame('shared-phone', $second->pushTokens()->where('app', 'rider')->value('token'));
    }

    public function test_turning_notifications_off_in_one_app_leaves_the_other_app_alone(): void
    {
        $user = $this->user();
        PushApp::register($user, 'rider', 'r-token');
        PushApp::register($user, 'partner', 'p-token');

        $this->actingAs($user)->withHeader('User-Agent', self::RIDER_UA)->deleteJson('/push/token')->assertOk();

        $this->assertSame(['partner'], $user->pushTokens()->pluck('app')->all());
    }

    public function test_a_dead_app_token_is_removed(): void
    {
        $user = $this->user();
        PushApp::register($user, 'rider', 'dead-token');
        $adapter = new class implements \App\Contracts\PushAdapter {
            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                throw new InvalidPushTokenException($token);
            }
        };

        (new PushChannel($adapter))->send($user, new RiderParcelOfferNotification(new ParcelOrder()));

        $this->assertSame(0, UserPushToken::count());
    }

    // ═════════════════════ buttons open the other app ═════════════════════

    public function test_in_the_partner_app_the_rider_button_opens_the_rider_app_and_in_a_browser_it_switches_dashboards(): void
    {
        $w = $this->plumberRider();
        $this->actingAs($w['rider']->user);

        request()->headers->set('User-Agent', self::PARTNER_UA);
        $inApp = Blade::render('<x-partner.mode-switch current="service" />');
        $this->assertStringContainsString('Open the Rider app', $inApp);
        $this->assertStringContainsString('play.google.com/store/apps/details?id=com.onecall.deliveryboy', $inApp);

        request()->headers->set('User-Agent', 'Mozilla/5.0 Chrome/120');
        $browser = Blade::render('<x-partner.mode-switch current="service" />');
        $this->assertStringContainsString('Switch to rider mode', $browser);
        $this->assertStringContainsString(route('rider.dashboard'), $browser);
    }

    public function test_in_the_rider_app_the_services_button_opens_the_partner_app(): void
    {
        $w = $this->plumberRider();
        $this->actingAs($w['rider']->user);

        request()->headers->set('User-Agent', self::RIDER_UA);
        $html = Blade::render('<x-partner.mode-switch current="parcel" />');

        $this->assertStringContainsString('Open the Partner app', $html);
        $this->assertStringContainsString('id=com.onecall.vendor', $html);
        $this->assertStringNotContainsString(route('provider.dashboard'), $html);
    }

    // ═════════════════════ wrong-app guard ═════════════════════

    public function test_a_partner_page_opened_in_the_rider_app_says_open_the_partner_app(): void
    {
        $w = $this->plumberRider();

        $this->actingAs($w['rider']->user)->withHeader('User-Agent', self::RIDER_UA)
            ->get(route('provider.dashboard'))
            ->assertOk()->assertSee('data-testid="open-other-app"', false)
            ->assertSee('This is in the 1CallFix Partner app')->assertSee('id=com.onecall.vendor', false);
    }

    public function test_a_rider_page_opened_in_the_partner_app_says_open_the_rider_app_but_browsers_open_it(): void
    {
        $w = $this->plumberRider();

        $this->actingAs($w['rider']->user)->withHeader('User-Agent', self::PARTNER_UA)
            ->get(route('rider.dashboard'))
            ->assertOk()->assertSee('This is in the 1CallFix Rider app')->assertSee('id=com.onecall.deliveryboy', false);

        $this->actingAs($w['rider']->user)->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120')
            ->get(route('rider.dashboard'))
            ->assertOk()->assertDontSee('data-testid="open-other-app"', false);
    }

    // ═════════════════════ no delivery offers while a service job is in progress ═════════════════════

    public function test_a_rider_doing_a_service_job_gets_no_delivery_offers_but_does_while_only_on_the_way(): void
    {
        $w = $this->plumberRider();
        $order = $this->parcelOrder($w);
        $booking = $this->makeBookingScenario('assigned')['booking'];
        $booking->update(['provider_id' => $w['provider']->id]);

        // Assigned / on the way to the house: deliveries still come (owner: he may take both).
        $this->assertTrue($this->offered($order, $w['rider']->id));

        // Start code given, work in progress: no delivery offers.
        $booking->update(['status' => 'in_progress']);
        $this->assertFalse($this->offered($order, $w['rider']->id));
        $this->assertTrue($w['rider']->fresh()->isDoingServiceWork());

        // Job done: offers come again.
        $booking->update(['status' => 'completed']);
        $this->assertTrue($this->offered($order, $w['rider']->id));
    }

    public function test_a_rider_without_a_service_account_is_unaffected(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);
        // Somebody else's service job in progress must not block this rider.
        $this->makeBookingScenario('in_progress')['booking']->update(['provider_id' => $this->makeBookingScenario()['provider']->id]);

        $order = $this->parcelOrder(compact('franchise', 'zone', 'customer', 'pickup', 'dropoff'));

        $this->assertTrue($this->offered($order, $rider->id));
        $this->assertFalse($rider->isDoingServiceWork());
    }
}
