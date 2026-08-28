<?php

namespace Tests\Feature\BookingBundle;

use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\EntitlementBalance;
use App\Models\FranchiseServicePricing;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\PaymentStatusNotification;
use App\Services\Plans\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E3 (Multi-Service Booking — Payment). A BookingBundle is paid through
 * the SAME payment architecture a single booking already uses:
 *
 *   POST /api/booking-bundles/{id}/pay/create-order  -> one Payment
 *        (purpose='booking_bundle') + one Razorpay order for the
 *        server-authoritative aggregate total
 *   POST /api/booking-bundles/{id}/pay/confirm       -> provisional client ack
 *   POST /api/webhooks/razorpay (existing endpoint)  -> RazorpayWebhookHandler
 *        captures the payment, marks the bundle paid, propagates paid state to
 *        every child booking, notifies each child's customer once
 *
 * Razorpay's network boundary is the ONLY thing faked (Http::fake on the
 * orders API + config fake credentials, exactly as PaymentGatewayTest does).
 * Every payment-record / amount-validation / bundle-association / signature /
 * state-transition / idempotency / authorization path runs for real.
 */
class BookingBundlePaymentTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Real Razorpay credentials never exist in this environment — fake,
        // obviously-non-real values + faked HTTP, same as PaymentGatewayTest.
        config([
            'services.razorpay.key_id' => 'rzp_test_bundlekey123',
            'services.razorpay.key_secret' => 'fake-bundle-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-bundle-webhook-secret-never-real',
        ]);

        // Stop the real per-child ServiceMatchingJob from running during
        // bundle creation — E3 is payment only, dispatch is out of scope.
        Queue::fake();
    }

    // ============================== fixtures ==============================

    private function world(float $priceA = 1000, float $priceB = 500): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $franchise->update(['code' => 'E3P'.str_pad((string) self::$seq++, 3, '0', STR_PAD_LEFT)]);

        $category = $this->makeCategory();
        $serviceA = $this->makeService($category, ['name' => 'Deep Clean', 'base_price' => $priceA]);
        $serviceB = $this->makeService($category, ['name' => 'Pest Control', 'base_price' => $priceB]);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        return compact('country', 'city', 'franchise', 'zone', 'category', 'serviceA', 'serviceB', 'customer', 'address');
    }

    /** Create a real bundle via the E2 endpoint. 'online' => payment_status 'pending', no Payment row yet. */
    private function makeBundle(array $world, string $method = 'online', ?array $services = null): BookingBundle
    {
        $services ??= [
            ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
            ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
        ];

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', ['payment_method' => $method, 'services' => $services])
            ->assertStatus(201);

        return BookingBundle::latest('id')->firstOrFail();
    }

    private function fakeOrders(string $orderId = 'order_bundle_1', int $amountPaise = 150000): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(
                ['id' => $orderId, 'amount' => $amountPaise, 'currency' => 'INR'],
                200,
            ),
        ]);
    }

    /**
     * A distinct order id per gateway call, in order — for tests that open
     * more than one bundle order. `Http::fake()` does not override a stub for
     * the same URL on a second call, so a plain re-fake would hand both
     * orders the same id.
     *
     * @param  array<int, array{0:string, 1:int}>  $orders  [ [orderId, amountPaise], ... ]
     */
    private function fakeOrderSequence(array $orders): void
    {
        $sequence = Http::sequence();
        foreach ($orders as [$orderId, $amountPaise]) {
            $sequence->push(['id' => $orderId, 'amount' => $amountPaise, 'currency' => 'INR'], 200);
        }

        Http::fake(['api.razorpay.com/v1/orders' => $sequence]);
    }

    private function createOrder(BookingBundle $bundle, ?object $as = null, array $body = [])
    {
        return $this->actingAs($as ?? $bundle->customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/pay/create-order", $body);
    }

    private function checkoutSignature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', "{$orderId}|{$paymentId}", config('services.razorpay.key_secret'));
    }

    private function confirm(BookingBundle $bundle, string $orderId, string $paymentId, ?string $signature = null, ?object $as = null)
    {
        return $this->actingAs($as ?? $bundle->customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/pay/confirm", [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature ?? $this->checkoutSignature($orderId, $paymentId),
            ]);
    }

    private function webhookSignature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret'));
    }

    private function capturedPayload(string $orderId, string $paymentId = 'pay_bundle_1'): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['order_id' => $orderId, 'id' => $paymentId]]]];
    }

    private function failedPayload(string $orderId, string $paymentId = 'pay_bundle_fail_1'): array
    {
        return ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['order_id' => $orderId, 'id' => $paymentId]]]];
    }

    private function postWebhook(array $payload, ?string $signature = null)
    {
        return $this->postJson('/api/webhooks/razorpay', $payload, [
            'X-Razorpay-Signature' => $signature ?? $this->webhookSignature($payload),
        ]);
    }

    private function ordersSentCount(): int
    {
        return Http::recorded(
            fn ($request) => str_contains($request->url(), 'api.razorpay.com/v1/orders')
        )->count();
    }

    // ============================== 1. create-order ==============================

    public function test_a_customer_can_create_a_razorpay_order_for_their_bundle(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_bundle_1', 150000);

        $response = $this->createOrder($bundle)->assertOk();

        $response->assertJsonPath('data.razorpay_order_id', 'order_bundle_1');
        $response->assertJsonPath('data.amount', 150000);
        $response->assertJsonPath('data.currency', 'INR');
        $response->assertJsonPath('data.razorpay_key_id', 'rzp_test_bundlekey123');

        $payments = Payment::where('purpose', 'booking_bundle')->get();
        $this->assertCount(1, $payments);
        $payment = $payments->first();
        $this->assertSame($bundle->id, (int) $payment->booking_bundle_id);
        $this->assertSame('razorpay', $payment->gateway);
        $this->assertSame('order_bundle_1', $payment->gateway_order_id);
        $this->assertSame('pending', $payment->status);
        $this->assertEqualsWithDelta(1500.0, (float) $payment->amount, 0.001);
        $this->assertNull($payment->booking_id, 'A bundle payment is not a child-booking payment.');
    }

    public function test_create_order_and_confirm_require_authentication(): void
    {
        // No actingAs anywhere in this test — a genuinely tokenless request.
        // The bundle id is irrelevant: auth:sanctum runs before the controller.
        $this->postJson('/api/booking-bundles/1/pay/create-order')->assertStatus(401);
        $this->postJson('/api/booking-bundles/1/pay/confirm')->assertStatus(401);
        $this->assertSame(0, Payment::where('purpose', 'booking_bundle')->count());
    }

    // ============================== 2. one payment, reused ==============================

    public function test_repeated_create_order_reuses_the_single_pending_payment_and_order(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_bundle_reuse', 150000);

        $first = $this->createOrder($bundle)->assertOk();
        $second = $this->createOrder($bundle)->assertOk();

        $this->assertSame($first->json('data.payment_id'), $second->json('data.payment_id'), 'Same Payment row is reused.');
        $this->assertSame('order_bundle_reuse', $second->json('data.razorpay_order_id'));
        $this->assertSame(150000, $second->json('data.amount'), 'Reused order keeps the authoritative amount.');
        $this->assertSame('rzp_test_bundlekey123', $second->json('data.razorpay_key_id'));

        $this->assertSame(1, Payment::where('booking_bundle_id', $bundle->id)->count(), 'Exactly one payment record — never a duplicate.');
        $this->assertSame(1, $this->ordersSentCount(), 'The second call did NOT open a second gateway order.');
    }

    // ============================== 3 + 4. server-authoritative amount ==============================

    public function test_the_gateway_order_amount_is_the_server_authoritative_bundle_total(): void
    {
        $world = $this->world(1000, 500); // aggregate = 1500
        $bundle = $this->makeBundle($world);
        $this->assertEqualsWithDelta(1500.0, (float) $bundle->total_price_quoted, 0.001);
        $this->fakeOrders('order_amt', 150000);

        $this->createOrder($bundle)->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.razorpay.com/v1/orders')
            && (int) $request['amount'] === 150000
            && $request['currency'] === 'INR');

        $this->assertEqualsWithDelta(1500.0, (float) Payment::where('booking_bundle_id', $bundle->id)->value('amount'), 0.001);
    }

    public function test_a_client_supplied_amount_has_zero_influence_on_the_gateway_order(): void
    {
        $world = $this->world(1000, 500); // authoritative aggregate = 1500
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_manip', 150000);

        // Every name a client might try, plus the bundle/child price fields.
        $this->createOrder($bundle, body: [
            'amount' => 1, 'price' => 1, 'total' => 1, 'total_price_quoted' => 1,
            'total_price_final' => 1, 'discount' => 9999,
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.razorpay.com/v1/orders')
            && (int) $request['amount'] === 150000);

        $this->assertEqualsWithDelta(1500.0, (float) Payment::where('booking_bundle_id', $bundle->id)->value('amount'), 0.001);
    }

    public function test_create_order_needs_no_amount_field_at_all(): void
    {
        $world = $this->world(700, 300); // aggregate = 1000
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_noamt', 100000);

        $this->createOrder($bundle, body: [])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.razorpay.com/v1/orders')
            && (int) $request['amount'] === 100000);
    }

    // ============================== 5. confirmation ==============================

    public function test_a_valid_confirmation_records_the_payment_without_capturing_it(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_confirm_ok', 150000);
        $this->createOrder($bundle)->assertOk();

        $this->confirm($bundle, 'order_confirm_ok', 'pay_confirm_ok')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $payment = Payment::where('booking_bundle_id', $bundle->id)->firstOrFail();
        $this->assertSame('pay_confirm_ok', $payment->gateway_payment_id);
        $this->assertNotNull($payment->gateway_signature);
        $this->assertSame('pending', $payment->status, 'confirm() is provisional — only the webhook captures.');
        $this->assertSame('pending', $bundle->fresh()->payment_status);
        $this->assertTrue(Booking::where('booking_bundle_id', $bundle->id)->get()->every(fn ($c) => $c->payment_status === 'pending'));
    }

    // ============================== 6. invalid signature ==============================

    public function test_confirmation_with_an_invalid_signature_is_rejected(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_badsig', 150000);
        $this->createOrder($bundle)->assertOk();

        $this->confirm($bundle, 'order_badsig', 'pay_x', signature: 'not-the-real-signature')
            ->assertStatus(422);

        $payment = Payment::where('booking_bundle_id', $bundle->id)->firstOrFail();
        $this->assertNull($payment->gateway_payment_id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('pending', $bundle->fresh()->payment_status);
    }

    public function test_a_bundle_capture_webhook_with_an_invalid_signature_captures_nothing(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_wh_badsig', 150000);
        $this->createOrder($bundle)->assertOk();

        // Faked only now — bundle creation itself sends per-child "created"
        // notifications that are not part of what this test is asserting.
        Notification::fake();

        $this->postWebhook($this->capturedPayload('order_wh_badsig'), signature: 'wrong')
            ->assertStatus(400);

        $this->assertSame('pending', Payment::where('booking_bundle_id', $bundle->id)->value('status'));
        $this->assertSame('pending', $bundle->fresh()->payment_status);
        $this->assertTrue(Booking::where('booking_bundle_id', $bundle->id)->get()->every(fn ($c) => $c->payment_status === 'pending'));
        Notification::assertNothingSent();
        $this->assertDatabaseHas('payment_webhook_logs', ['outcome' => 'invalid_signature', 'signature_valid' => 0, 'processed' => 0]);
    }

    // ============================== 7 + 8. wrong payment / wrong bundle ==============================

    public function test_a_customer_cannot_confirm_their_bundle_with_another_bundles_order_id(): void
    {
        $world = $this->world(1000, 500);
        $bundleA = $this->makeBundle($world);
        $bundleB = $this->makeBundle($world);

        $this->fakeOrderSequence([['order_A', 150000], ['order_B', 150000]]);
        $this->createOrder($bundleA)->assertOk()->assertJsonPath('data.razorpay_order_id', 'order_A');
        $this->createOrder($bundleB)->assertOk()->assertJsonPath('data.razorpay_order_id', 'order_B');

        // Same customer owns both — but bundle A's order id is not bundle B's
        // pending payment, so this must not touch bundle B (or bundle A).
        $this->confirm($bundleB, 'order_A', 'pay_cross', $this->checkoutSignature('order_A', 'pay_cross'))
            ->assertStatus(404);

        $this->assertNull(Payment::where('gateway_order_id', 'order_A')->value('gateway_payment_id'), 'Bundle A payment untouched.');
        $this->assertNull(Payment::where('gateway_order_id', 'order_B')->value('gateway_payment_id'), 'Bundle B payment untouched.');
        $this->assertSame('pending', $bundleA->fresh()->payment_status);
        $this->assertSame('pending', $bundleB->fresh()->payment_status);
    }

    public function test_a_capture_webhook_only_pays_the_bundle_that_owns_the_order(): void
    {
        $world = $this->world(1000, 500);
        $bundleA = $this->makeBundle($world);
        $bundleB = $this->makeBundle($world);

        $this->fakeOrderSequence([['order_only_A', 150000], ['order_only_B', 150000]]);
        $this->createOrder($bundleA)->assertOk()->assertJsonPath('data.razorpay_order_id', 'order_only_A');
        $this->createOrder($bundleB)->assertOk()->assertJsonPath('data.razorpay_order_id', 'order_only_B');

        Notification::fake();
        $this->postWebhook($this->capturedPayload('order_only_A'))->assertOk();

        $this->assertSame('paid', $bundleA->fresh()->payment_status);
        $this->assertSame('pending', $bundleB->fresh()->payment_status, 'A payment for bundle A must never mark bundle B paid.');
        $this->assertTrue(Booking::where('booking_bundle_id', $bundleB->id)->get()->every(fn ($c) => $c->payment_status === 'pending'));
    }

    // ============================== 9. wrong customer / IDOR ==============================

    public function test_another_customer_cannot_create_an_order_or_confirm_for_a_bundle(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $intruder = $this->makeCustomer();
        $this->fakeOrders('order_idor', 150000);

        $this->createOrder($bundle, as: $intruder)->assertStatus(404);
        $this->assertSame(0, Payment::where('purpose', 'booking_bundle')->count());
        Http::assertNothingSent();

        // Owner creates the real order; intruder still cannot confirm it.
        $this->createOrder($bundle)->assertOk();
        $this->confirm($bundle, 'order_idor', 'pay_idor', as: $intruder)->assertStatus(404);
        $this->assertNull(Payment::where('gateway_order_id', 'order_idor')->value('gateway_payment_id'));
    }

    // ============================== 10 + 13. duplicate webhook idempotency ==============================

    public function test_a_duplicate_capture_webhook_does_not_re_run_bundle_side_effects(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $children = Booking::where('booking_bundle_id', $bundle->id)->get();
        $this->fakeOrders('order_dup', 150000);
        $this->createOrder($bundle)->assertOk();

        Notification::fake();
        $payload = $this->capturedPayload('order_dup');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk(); // exact same event, delivered twice

        $this->assertSame('paid', $bundle->fresh()->payment_status);
        $this->assertSame(1, Payment::where('booking_bundle_id', $bundle->id)->where('status', 'captured')->count());
        $this->assertSame(2, PaymentWebhookLog::where('gateway_order_id', 'order_dup')->count());
        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_order_id' => 'order_dup', 'outcome' => 'already_processed']);

        // One "payment received" per child booking (all children share this
        // one customer), and — the point of this test — the duplicate webhook
        // did NOT double it to 2 x children.
        Notification::assertSentToTimes($world['customer'], PaymentStatusNotification::class, $children->count());
        Notification::assertCount($children->count());
    }

    // ============================== 11 + 12. paid state propagation ==============================

    public function test_a_capture_webhook_marks_the_bundle_and_every_child_booking_paid(): void
    {
        $world = $this->world(1200, 800);
        $bundle = $this->makeBundle($world, services: [
            ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
            ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
            ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
        ]);
        $this->assertSame(3, Booking::where('booking_bundle_id', $bundle->id)->count());
        $this->fakeOrders('order_prop', 320000);
        $this->createOrder($bundle)->assertOk();

        Notification::fake();
        $this->postWebhook($this->capturedPayload('order_prop'))->assertOk();

        $this->assertSame('paid', $bundle->fresh()->payment_status);
        $this->assertSame('captured', Payment::where('booking_bundle_id', $bundle->id)->value('status'));
        $this->assertSame(
            3,
            Booking::where('booking_bundle_id', $bundle->id)->where('payment_status', 'paid')->count(),
            'Every child booking must be marked paid.',
        );
    }

    // ============================== 14. failed payment ==============================

    public function test_a_failed_bundle_webhook_leaves_the_bundle_and_children_unpaid(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        $this->fakeOrders('order_fail', 150000);
        $this->createOrder($bundle)->assertOk();

        Notification::fake();
        $this->postWebhook($this->failedPayload('order_fail'))->assertOk();

        $this->assertSame('failed', Payment::where('booking_bundle_id', $bundle->id)->value('status'));
        $this->assertSame('pending', $bundle->fresh()->payment_status);
        $this->assertTrue(Booking::where('booking_bundle_id', $bundle->id)->get()->every(fn ($c) => $c->payment_status === 'pending'));
        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_order_id' => 'order_fail', 'outcome' => 'failed']);
    }

    // ============================== 5.5 rejection paths ==============================

    public function test_create_order_is_rejected_for_an_already_paid_bundle(): void
    {
        $world = $this->world(1000, 500);
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 5000]);
        $bundle = $this->makeBundle($world, method: 'wallet'); // paid at creation by E2 wallet path
        $this->assertSame('paid', $bundle->fresh()->payment_status);
        $this->fakeOrders('order_should_not_happen', 150000);

        $this->createOrder($bundle)->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame(1, Payment::where('booking_bundle_id', $bundle->id)->count(), 'Still only the wallet payment.');
    }

    public function test_disabled_online_payments_blocks_bundle_create_order(): void
    {
        $world = $this->world(1000, 500);
        $bundle = $this->makeBundle($world);
        Setting::set('payment.online_enabled', '0');
        $this->fakeOrders();

        $this->createOrder($bundle)->assertStatus(422);

        $this->assertSame(0, Payment::where('purpose', 'booking_bundle')->count());
        Http::assertNothingSent();
    }

    // ============================== 15 + 16. wallet ==============================

    public function test_wallet_bundle_payment_debits_the_exact_aggregate_and_marks_children_paid(): void
    {
        $world = $this->world(1000, 500); // aggregate = 1500
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 2000]);

        $bundle = $this->makeBundle($world, method: 'wallet');

        $debits = WalletTransaction::where('is_credit', false)->get();
        $this->assertCount(1, $debits, 'ONE aggregate debit, never one per child.');
        $this->assertEqualsWithDelta(1500.0, (float) $debits->first()->amount, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $world['customer']->wallet->fresh()->balance, 0.001);

        $payment = Payment::where('booking_bundle_id', $bundle->id)->firstOrFail();
        $this->assertSame('wallet', $payment->gateway);
        $this->assertSame('captured', $payment->status);
        $this->assertEqualsWithDelta(1500.0, (float) $payment->amount, 0.001);

        $this->assertSame('paid', $bundle->fresh()->payment_status);
        $this->assertSame(2, Booking::where('booking_bundle_id', $bundle->id)->where('payment_status', 'paid')->count());
    }

    public function test_insufficient_wallet_balance_pays_nothing_and_creates_no_bundle(): void
    {
        $world = $this->world(1000, 500); // aggregate = 1500
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 1200]);

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', [
                'payment_method' => 'wallet',
                'services' => [
                    ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
                    ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
                ],
            ])
            ->assertStatus(409);

        $this->assertSame(0, BookingBundle::count());
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, WalletTransaction::where('is_credit', false)->count());
        $this->assertEqualsWithDelta(1200.0, (float) $world['customer']->wallet->fresh()->balance, 0.001);
    }

    // ============================== 17. wallet double-spend across bundles ==============================

    public function test_one_wallet_cannot_pay_two_bundles_that_together_exceed_the_balance(): void
    {
        $world = $this->world(1000, 500); // each bundle aggregate = 1500
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 2000]);

        $body = [
            'payment_method' => 'wallet',
            'services' => [
                ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
                ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
            ],
        ];

        $this->actingAs($world['customer'], 'sanctum')->postJson('/api/booking-bundles', $body)->assertStatus(201);
        $this->actingAs($world['customer'], 'sanctum')->postJson('/api/booking-bundles', $body)->assertStatus(409);

        $this->assertEqualsWithDelta(500.0, (float) $world['customer']->wallet->fresh()->balance, 0.001);
        $this->assertSame(1, WalletTransaction::where('is_credit', false)->count());
        $this->assertLessThanOrEqual(2000.0, (float) WalletTransaction::where('is_credit', false)->sum('amount'));
        $this->assertSame(1, Payment::where('purpose', 'booking_bundle')->count());
    }

    // ============================== 18-20. discounted aggregate flows to the gateway ==============================

    public function test_a_flash_sale_child_price_flows_into_the_bundle_gateway_amount(): void
    {
        $world = $this->world(500, 500);
        $this->makeFlashSale([$world['serviceA']], ['discount_type' => 'percent', 'discount_value' => 20]); // A: 400

        $bundle = $this->makeBundle($world);
        $this->assertEqualsWithDelta(900.0, (float) $bundle->total_price_quoted, 0.001);
        $this->fakeOrders('order_flash', 90000);

        $this->createOrder($bundle)->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/orders') && (int) $r['amount'] === 90000);
        $this->assertEqualsWithDelta(900.0, (float) Payment::where('booking_bundle_id', $bundle->id)->value('amount'), 0.001);
    }

    public function test_a_membership_discount_flows_into_the_bundle_gateway_amount(): void
    {
        $world = $this->world(500, 500);
        $plan = Plan::create([
            'name' => 'QA Membership', 'slug' => 'qa-membership-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        PlanEntitlement::create([
            'plan_id' => $plan->id, 'usage_period' => 'monthly', 'consumption_trigger' => 'booking_created',
            'rollover_policy' => 'none', 'entitlement_type' => 'percentage_discount',
            'percentage_value' => 20, 'quantity' => 5,
        ]);
        $result = app(SubscriptionService::class)->initiateSubscribe($world['customer'], 'customer', $plan);
        $this->assertSame('active', Subscription::findOrFail($result['subscription_id'])->status);

        $bundle = $this->makeBundle($world); // 20% off each 500 => 800 total
        $this->assertEqualsWithDelta(800.0, (float) $bundle->total_price_quoted, 0.001);
        $this->fakeOrders('order_member', 80000);

        $this->createOrder($bundle)->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/orders') && (int) $r['amount'] === 80000);
        $this->assertEqualsWithDelta(800.0, (float) Payment::where('booking_bundle_id', $bundle->id)->value('amount'), 0.001);

        // The membership entitlement was consumed once per child (the ledger
        // detail itself is pinned in BundlePricingAuthorityTest); here we only
        // need that the discounted aggregate is what reaches the gateway.
        $this->assertGreaterThanOrEqual(1, EntitlementBalance::query()->count());
    }

    public function test_a_franchise_price_override_flows_into_the_bundle_gateway_amount(): void
    {
        $world = $this->world(500, 500);
        FranchiseServicePricing::create([
            'franchise_id' => $world['franchise']->id, 'service_id' => $world['serviceA']->id,
            'price_override' => 350, 'is_offered' => true,
        ]);

        $bundle = $this->makeBundle($world); // 350 + 500 = 850
        $this->assertEqualsWithDelta(850.0, (float) $bundle->total_price_quoted, 0.001);
        $this->fakeOrders('order_franchise', 85000);

        $this->createOrder($bundle)->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/orders') && (int) $r['amount'] === 85000);
        $this->assertEqualsWithDelta(850.0, (float) Payment::where('booking_bundle_id', $bundle->id)->value('amount'), 0.001);
    }

    // ============================== 23. end to end ==============================

    public function test_end_to_end_bundle_payment_create_order_confirm_then_webhook_capture(): void
    {
        $world = $this->world(1000, 500);

        // 3 children (A, B, A) — one bundle, one aggregate, N children.
        $bundle = $this->makeBundle($world, services: [
            ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
            ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
            ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
        ]);
        $children = Booking::where('booking_bundle_id', $bundle->id)->get();
        $this->assertCount(3, $children);
        $expectedTotal = (float) $children->sum('price_quoted');
        $this->assertEqualsWithDelta($expectedTotal, (float) $bundle->total_price_quoted, 0.001);

        $this->fakeOrders('order_e2e', (int) round($expectedTotal * 100));
        Notification::fake();

        // 1) create order
        $create = $this->createOrder($bundle)->assertOk();
        $this->assertSame('order_e2e', $create->json('data.razorpay_order_id'));

        // 2) client confirm (provisional)
        $this->confirm($bundle, 'order_e2e', 'pay_e2e')->assertOk();
        $this->assertSame('pending', Payment::where('booking_bundle_id', $bundle->id)->value('status'));
        $this->assertSame('pending', $bundle->fresh()->payment_status);

        // 3) authoritative webhook capture
        $this->postWebhook($this->capturedPayload('order_e2e', 'pay_e2e'))->assertOk();

        // final state — one bundle, one payment, one order, correct aggregate
        $this->assertSame(1, Payment::where('booking_bundle_id', $bundle->id)->count());
        $this->assertSame(1, $this->ordersSentCount());
        $payment = Payment::where('booking_bundle_id', $bundle->id)->firstOrFail();
        $this->assertSame('captured', $payment->status);
        $this->assertSame('pay_e2e', $payment->gateway_payment_id);
        $this->assertNotNull($payment->captured_at);
        $this->assertEqualsWithDelta($expectedTotal, (float) $payment->amount, 0.001);

        $this->assertSame('paid', $bundle->fresh()->payment_status);
        $this->assertSame(3, Booking::where('booking_bundle_id', $bundle->id)->where('payment_status', 'paid')->count());

        // one "payment received" per child booking, all to this one customer
        Notification::assertSentToTimes($world['customer'], PaymentStatusNotification::class, 3);

        // a late duplicate webhook changes nothing
        $this->postWebhook($this->capturedPayload('order_e2e', 'pay_e2e'))->assertOk();
        $this->assertSame(1, Payment::where('booking_bundle_id', $bundle->id)->where('status', 'captured')->count());
        Notification::assertSentToTimes($world['customer'], PaymentStatusNotification::class, 3);
        Notification::assertCount(3);
    }

    // ============================== 21. single-booking regression guard ==============================

    public function test_single_booking_payment_is_unchanged_by_the_bundle_path(): void
    {
        Notification::fake();
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['price_quoted' => 500]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_single', 'amount' => 50000, 'currency' => 'INR'], 200)]);

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/pay/create-order")
            ->assertOk()
            ->assertJsonPath('razorpay_order_id', 'order_single');

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id, 'purpose' => 'booking', 'gateway' => 'razorpay', 'status' => 'pending',
        ]);

        $this->postWebhook($this->capturedPayload('order_single', 'pay_single'))->assertOk();

        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'status' => 'captured']);
        Notification::assertSentToTimes($booking->customer, PaymentStatusNotification::class, 1);
    }
}
