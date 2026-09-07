<?php

namespace Tests\Feature\Push;

use App\Actions\CreateBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AdminOpsAlertNotification;
use App\Services\AdminOpsAlertService;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 2 — narrow admin operational push. An admin who ticks "Enable
 * order alerts" (users.push_ops_alerts + an fcm_token) gets a push on
 * booking-created and payment-captured. Nobody else does — this is NOT a
 * Notification Center broadcast.
 */
class AdminOpsAlertTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
    }

    private function admin(bool $optedIn, bool $withToken = true): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Ops Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin',
            'status' => 'active',
            'push_ops_alerts' => $optedIn,
            'fcm_token' => $withToken ? 'admin-web-token-'.Str::random(6) : null,
        ]);
    }

    public function test_only_opted_in_admins_with_a_token_receive_the_booking_created_alert(): void
    {
        Notification::fake();

        $optedIn = $this->admin(optedIn: true);
        $noFlag = $this->admin(optedIn: false);
        $noToken = $this->admin(optedIn: true, withToken: false);

        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        app(AdminOpsAlertService::class)->bookingCreated($booking);

        Notification::assertSentTo($optedIn, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($noFlag, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($noToken, AdminOpsAlertNotification::class);
    }

    public function test_payment_captured_alert_targets_the_same_audience(): void
    {
        Notification::fake();

        $optedIn = $this->admin(optedIn: true);
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $payment = Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'razorpay', 'status' => 'captured', 'captured_at' => now(),
        ]);

        app(AdminOpsAlertService::class)->paymentCaptured($payment);

        Notification::assertSentTo($optedIn, AdminOpsAlertNotification::class, function (AdminOpsAlertNotification $n) use ($booking) {
            return str_contains($n->pushLink($booking->customer), (string) $booking->id);
        });
    }

    public function test_creating_a_booking_fires_the_ops_alert_hook(): void
    {
        Notification::fake();

        $optedIn = $this->admin(optedIn: true);
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'online',
        ]);

        Notification::assertSentTo($optedIn, AdminOpsAlertNotification::class);
    }

    public function test_the_alert_only_materialises_on_push_and_never_touches_mail(): void
    {
        // AdminOpsAlertNotification has no toMail() — the service must
        // therefore hand it ONLY the push channel even though
        // notifications.channels resolves to ['mail', push], or the mail
        // channel would throw BadMethodCallException on send.
        $admin = $this->admin(optedIn: true);
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        app(AdminOpsAlertService::class)->bookingCreated($booking); // must not throw

        $this->assertDatabaseHas('notification_logs', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
            'notification_type' => AdminOpsAlertNotification::class,
            'channel' => 'push',
            'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('notification_logs', [
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
            'notification_type' => AdminOpsAlertNotification::class,
            'channel' => 'mail',
        ]);
    }

    public function test_turning_push_off_platform_wide_silences_the_ops_alert(): void
    {
        Setting::set('notifications.channels', 'mail');
        Notification::fake();

        $admin = $this->admin(optedIn: true);
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        app(AdminOpsAlertService::class)->bookingCreated($booking);

        Notification::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Wallet-paid captures — synchronous, never through the webhook
    // -----------------------------------------------------------------

    /** @return int number of payment_captured alerts sent to $admin */
    private function paymentCapturedAlerts(User $admin): int
    {
        return Notification::sent($admin, AdminOpsAlertNotification::class)
            ->filter(fn (AdminOpsAlertNotification $n) => $n->eventKey() === 'admin.ops_payment_captured')
            ->count();
    }

    public function test_a_wallet_paid_booking_fires_the_payment_captured_alert(): void
    {
        Notification::fake();
        Queue::fake();

        $admin = $this->admin(optedIn: true);
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Wallet::create(['user_id' => $customer->id, 'balance' => 10000]);

        app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'wallet',
        ]);

        $this->assertSame(1, $this->paymentCapturedAlerts($admin));
    }

    public function test_a_wallet_paid_bundle_fires_the_payment_captured_alert(): void
    {
        Notification::fake();
        Queue::fake();

        $admin = $this->admin(optedIn: true);
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        [, $service2] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Wallet::create(['user_id' => $customer->id, 'balance' => 100000]);

        app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $customer->id,
            'payment_method' => 'wallet',
            'idempotency_key' => null,
            'request_fingerprint' => 'fp-'.Str::random(8),
            'children' => [
                ['service_id' => $service->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'address_id' => $address->id, 'scheduled_at' => null, 'customer_note' => null],
                ['service_id' => $service2->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'address_id' => $address->id, 'scheduled_at' => null, 'customer_note' => null],
            ],
        ]);

        // ONE aggregate booking_bundle payment => exactly one capture alert,
        // even though the bundle has two child bookings.
        $this->assertSame(1, $this->paymentCapturedAlerts($admin));
    }

    public function test_a_gateway_paid_booking_fires_the_capture_alert_exactly_once_across_action_and_webhook(): void
    {
        Notification::fake();
        Queue::fake();
        config(['services.push.fcm.project_id' => null]); // keep the webhook's other side-effects inert

        $admin = $this->admin(optedIn: true);
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'online',
        ]);

        // The action alone must NOT have fired a capture alert for a gateway booking.
        $this->assertSame(0, $this->paymentCapturedAlerts($admin), 'CreateBookingAction must not fire payment_captured for a gateway booking.');

        // The order-creation step (a separate controller in real life) leaves
        // a pending Payment the webhook then captures.
        Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => $booking->price_quoted,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_gw_once', 'status' => 'pending',
        ]);

        app(RazorpayWebhookHandler::class)->handleCaptured([
            'payload' => ['payment' => ['entity' => ['order_id' => 'order_gw_once', 'id' => 'pay_gw_once']]],
        ]);

        $this->assertSame(1, $this->paymentCapturedAlerts($admin), 'Gateway capture must fire exactly once — from the webhook only.');
    }
}
