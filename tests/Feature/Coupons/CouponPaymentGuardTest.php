<?php

namespace Tests\Feature\Coupons;

use App\Actions\CreateBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Contracts\PaymentGateway;
use App\Exceptions\CouponException;
use App\Exceptions\OnlinePaymentRequiredException;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\BookingExtraItem;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Coupons\CouponHoldSweepService;
use App\Services\Coupons\CouponService;
use App\Services\Payments\BookingBundlePaymentService;
use App\Services\Payments\OnlinePaymentGuard;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 1CF-COUPON-HARDENING-003 §A-D, §I (docs/COUPON_HARDENING_003.md): ONE shared online-payment guard,
 * no cash / partial-cash route to a coupon, later extras never invalidate the original coupon, and the
 * bundle row cannot be flipped to cash. THUMB RULE: only online (Razorpay, wallet, or both) gets a benefit.
 */
class CouponPaymentGuardTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    private const MESSAGE = 'Offers apply on online payment only.';

    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory());
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');
        Setting::set('payment.wallet_enabled', '1');

        $coupon = Coupon::create(['code' => 'SAVE100', 'name' => 's', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 9]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);

        return compact('franchise', 'zone', 'service', 'customer', 'address', 'coupon');
    }

    private function book(array $w, array $extra = []): Booking
    {
        Queue::fake();

        return app(CreateBookingAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online',
        ], $extra));
    }

    private function bundle(array $w, string $method = 'online', int $children = 2): BookingBundle
    {
        Queue::fake();
        $rows = [];
        for ($i = 0; $i < $children; $i++) {
            $svc = $i === 0 ? $w['service'] : $this->makeService($this->makeCategory());
            $rows[] = ['service_id' => $svc->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'address_id' => $w['address']->id];
        }

        return app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $w['customer']->id, 'payment_method' => $method, 'idempotency_key' => null,
            'request_fingerprint' => 'fp-'.Str::random(6), 'coupon_code' => 'SAVE100', 'children' => $rows,
        ]);
    }

    private function fund(User $u, float $amount): void
    {
        app(WalletService::class)->credit($u, $amount, 'top-up', 'test:'.Str::random(8));
    }

    private function mockGateway(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('identifier')->andReturn('razorpay')->byDefault();
        $mock->shouldReceive('checkoutKeyId')->andReturn('rzp_test_key')->byDefault();
        $this->app->instance(PaymentGateway::class, $mock);

        return $mock;
    }

    // ───────────────────────── A: ONE shared guard ─────────────────────────

    #[DataProvider('methods')]
    public function test_the_shared_guard_allows_only_online_and_wallet(?string $method, bool $allowed): void
    {
        $this->assertSame($allowed, OnlinePaymentGuard::isOnline($method));
        if ($allowed) {
            OnlinePaymentGuard::assertOnline($method);
            $this->assertTrue(true);
        } else {
            $this->expectException(OnlinePaymentRequiredException::class);
            $this->expectExceptionMessage(self::MESSAGE);
            OnlinePaymentGuard::assertOnline($method);
        }
    }

    public static function methods(): array
    {
        return [['online', true], ['wallet', true], ['cash', false], ['split', false], ['cash+online', false], ['', false], [null, false], ['ONLINE ', false]];
    }

    public function test_10_a_cash_only_coupon_booking_is_rejected_server_side_with_the_exact_message(): void
    {
        $w = $this->world();

        try {
            $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'cash']);
            $this->fail('cash + coupon accepted');
        } catch (CouponException $e) {
            $this->assertSame('online_payment_required', $e->reason);
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, CouponUsage::count());

        try {
            $this->bundle($w, 'cash');
            $this->fail('cash bundle + coupon accepted');
        } catch (CouponException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertSame(0, BookingBundle::count());
    }

    // ───────────────────────── C: partial cash + online ─────────────────────────

    public function test_11_cash_plus_online_on_a_coupon_eligible_payment_is_rejected_whatever_the_split(): void
    {
        $w = $this->world();

        // Guard: every leg must be digital. 1 rupee online + the rest cash must not unlock the coupon.
        foreach ([
            [['method' => 'online', 'amount' => 200], ['method' => 'cash', 'amount' => 200]],
            [['method' => 'online', 'amount' => 1], ['method' => 'cash', 'amount' => 399]],
            [['method' => 'wallet', 'amount' => 399], ['method' => 'cash', 'amount' => 1]],
        ] as $legs) {
            $this->assertFalse(OnlinePaymentGuard::legsAreOnline($legs));
            try {
                OnlinePaymentGuard::assertLegsOnline($legs);
                $this->fail('split with a cash leg accepted');
            } catch (OnlinePaymentRequiredException $e) {
                $this->assertSame(self::MESSAGE, $e->getMessage());
            }
        }
        $this->assertFalse(OnlinePaymentGuard::legsAreOnline([]), 'No legs is not a payment.');

        // Engine: any "split" style method string never reaches a benefit.
        $booking = $this->book($w);
        $ctxBuilder = app(\App\Services\Coupons\ServicePromotionContextBuilder::class);
        foreach (['split', 'cash+online', 'partial', 'cash_online'] as $method) {
            $booking->payment_method = $method;
            $r = app(CouponService::class)->validate($ctxBuilder->forBooking($booking, 'SAVE100'));
            $this->assertFalse($r->eligible, $method);
            $this->assertSame('online_payment_required', $r->reasonCode);
            $this->assertSame(self::MESSAGE, $r->message);
        }
    }

    public function test_c_split_payment_does_not_exist_today_so_only_a_rejecting_guard_is_added(): void
    {
        // Evidence for the report: no split / partial-cash column exists on bookings or payments.
        $this->assertFalse(\Schema::hasColumn('bookings', 'cash_amount'));
        $this->assertFalse(\Schema::hasColumn('payments', 'method'));
    }

    // ───────────────────────── eligible: 12 / 13 / 14 ─────────────────────────

    public function test_12_razorpay_only_is_eligible(): void
    {
        $w = $this->world();
        $b = $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'online']);

        $this->assertNotNull($b->coupon_id);
        $this->assertSame('online', $b->payment_method);
        $this->assertEquals(400.00, $b->amountPayable());
    }

    public function test_13_wallet_only_is_eligible(): void
    {
        $w = $this->world();
        $this->fund($w['customer'], 1000);
        $b = $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);

        $this->assertNotNull($b->coupon_id);
        $this->assertSame('paid', $b->fresh()->payment_status);
    }

    public function test_14_wallet_plus_razorpay_is_eligible_when_every_leg_is_digital(): void
    {
        $this->assertTrue(OnlinePaymentGuard::legsAreOnline([['method' => 'wallet', 'amount' => 150], ['method' => 'online', 'amount' => 250]]));
        OnlinePaymentGuard::assertLegsOnline([['method' => 'wallet', 'amount' => 150], ['method' => 'online', 'amount' => 250]]);
    }

    // ───────────────────────── D: later extras ─────────────────────────

    public function test_15_online_paid_coupon_booking_stays_valid_when_a_later_extra_is_added_and_the_guard_stays_strict(): void
    {
        $w = $this->world();
        $this->fund($w['customer'], 1000);
        $b = $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);

        // Mid-job approved extra work: recorded on its own table, never on bookings.payment_method.
        $provider = $this->makeProviderIn($w['franchise'], $w['zone']);
        BookingExtraItem::create(['booking_id' => $b->id, 'description' => 'Extra part', 'amount' => 200, 'status' => 'approved', 'added_by_provider_id' => $provider->id]);
        $b->status = 'completed';
        $b->price_final = (float) $b->price_quoted + 200; // exactly what CompleteBookingAction does
        $b->save();

        $fresh = $b->fresh();
        $this->assertSame('wallet', $fresh->payment_method, 'Extras never touch the original payment method.');
        $this->assertNotNull($fresh->coupon_id);
        $this->assertEquals(100.00, (float) $fresh->coupon_discount_amount);
        $this->assertSame('confirmed', CouponUsage::firstOrFail()->status, 'The original coupon is still valid and realised.');

        // …and the guard stays strict everywhere else.
        try {
            $fresh->payment_method = 'cash';
            $fresh->save();
            $this->fail('switch to cash allowed');
        } catch (OnlinePaymentRequiredException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->expectException(CouponException::class);
        $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'cash']);
    }

    // ───────────────────────── 16 / 17 / 18: no route to cash ─────────────────────────

    public function test_16_payment_retry_cannot_convert_a_coupon_booking_to_cash(): void
    {
        $w = $this->world();
        $b = $this->book($w, ['coupon_code' => 'SAVE100']);

        $mock = $this->mockGateway();
        $mock->shouldReceive('createOrder')->twice()->andReturn(['razorpay_order_id' => 'order_X', 'key_id' => 'k', 'amount' => 40000, 'currency' => 'INR']);

        foreach ([1, 2] as $_) {
            $this->actingAs($w['customer'], 'sanctum')->postJson("/api/bookings/{$b->id}/pay/create-order")->assertOk();
        }

        $fresh = $b->fresh();
        $this->assertSame('online', $fresh->payment_method);
        $this->assertNotNull($fresh->coupon_id);
        $this->assertEquals(400.00, (float) Payment::where('booking_id', $b->id)->latest('id')->value('amount'), 'Retry charges the discounted amount, online.');

        $this->expectException(OnlinePaymentRequiredException::class);
        $fresh->update(['payment_method' => 'cash']);
    }

    public function test_17_unpaid_hold_expiry_cannot_convert_a_coupon_booking_to_cash(): void
    {
        $w = $this->world();
        $b = $this->book($w, ['coupon_code' => 'SAVE100']);

        $this->travel(31)->minutes();
        $this->assertSame(1, app(CouponHoldSweepService::class)->sweep());

        $fresh = $b->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('online', $fresh->payment_method, 'Expiry cancels; it never rewrites the method.');
        $this->assertSame('released', CouponUsage::firstOrFail()->status);

        $this->expectException(OnlinePaymentRequiredException::class);
        $fresh->update(['payment_method' => 'cash']);
    }

    public function test_18_an_admin_cannot_change_a_coupon_booking_to_cash(): void
    {
        $w = $this->world();
        $b = $this->book($w, ['coupon_code' => 'SAVE100']);
        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin);

        try {
            Booking::findOrFail($b->id)->update(['payment_method' => 'cash']);
            $this->fail('admin converted a coupon booking to cash');
        } catch (OnlinePaymentRequiredException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertSame('online', $b->fresh()->payment_method);
        $this->assertNotNull($b->fresh()->coupon_id);

        // A booking with no benefit is unaffected (the rule is about benefits, not about cash).
        $plain = $this->book($w);
        $plain->update(['payment_method' => 'cash']);
        $this->assertSame('cash', $plain->fresh()->payment_method);
    }

    // ───────────────────────── 9 / I: bundle cash conversion ─────────────────────────

    public function test_9_a_coupon_bundle_parent_cannot_change_to_cash_and_nothing_becomes_inconsistent(): void
    {
        $w = $this->world();
        $bundle = $this->bundle($w);
        $this->assertNotNull($bundle->fresh()->coupon_id);

        try {
            $bundle->payment_method = 'cash';
            $bundle->save();
            $this->fail('bundle parent switched to cash');
        } catch (OnlinePaymentRequiredException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }

        $fresh = $bundle->fresh();
        $this->assertSame('online', $fresh->payment_method);
        $this->assertNotNull($fresh->coupon_id, 'No coupon is dropped to make cash possible.');
        $this->assertEquals(100.00, (float) $fresh->coupon_discount_amount);

        $methods = Booking::where('booking_bundle_id', $bundle->id)->pluck('payment_method')->unique()->all();
        $this->assertSame(['online'], $methods, 'No child payment method diverges from the parent.');
    }

    public function test_i_every_bypass_on_a_coupon_bundle_is_closed(): void
    {
        $w = $this->world();
        $bundle = $this->bundle($w);
        $children = Booking::where('booking_bundle_id', $bundle->id)->get();

        // admin-style mutation of the bundle row
        try {
            BookingBundle::findOrFail($bundle->id)->update(['payment_method' => 'cash']);
            $this->fail('admin bundle mutation allowed');
        } catch (OnlinePaymentRequiredException) {
            $this->assertTrue(true);
        }

        // a child carrying its share cannot leave the bundle's method…
        try {
            $children[0]->update(['payment_method' => 'cash']);
            $this->fail('coupon child switched to cash');
        } catch (OnlinePaymentRequiredException) {
            $this->assertTrue(true);
        }

        // …and neither can a child that carries no coupon_id of its own (e.g. member-priced) inside a coupon bundle.
        $bare = $children[1];
        \DB::table('bookings')->where('id', $bare->id)->update(['coupon_id' => null, 'coupon_discount_amount' => 0]);
        try {
            Booking::findOrFail($bare->id)->update(['payment_method' => 'cash']);
            $this->fail('child of a coupon bundle switched to cash');
        } catch (OnlinePaymentRequiredException) {
            $this->assertTrue(true);
        }

        // payment retry on the bundle keeps it online
        $mock = $this->mockGateway();
        $mock->shouldReceive('createRawOrder')->andReturn(['razorpay_order_id' => 'order_B', 'key_id' => 'k', 'amount' => 90000, 'currency' => 'INR']);
        app(BookingBundlePaymentService::class)->createOrder($bundle->fresh());
        app(BookingBundlePaymentService::class)->createOrder($bundle->fresh());

        $this->assertSame('online', $bundle->fresh()->payment_method);
        $this->assertSame(['online'], Booking::where('booking_bundle_id', $bundle->id)->pluck('payment_method')->unique()->all());
    }

    public function test_i_a_bundle_without_a_benefit_can_still_change_method(): void
    {
        $w = $this->world();
        Queue::fake();
        $bundle = app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $w['customer']->id, 'payment_method' => 'online', 'idempotency_key' => null, 'request_fingerprint' => 'fp-plain',
            'children' => [['service_id' => $w['service']->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'address_id' => $w['address']->id]],
        ]);

        $bundle->update(['payment_method' => 'cash']);
        $this->assertSame('cash', $bundle->fresh()->payment_method);
    }

    // ───────────────────────── 43 (coupon rows): cash rejected, online accepted ─────────────────────────

    public function test_43_coupon_benefit_is_rejected_on_cash_and_accepted_online_for_booking_and_bundle(): void
    {
        $w = $this->world();
        $this->fund($w['customer'], 5000);

        foreach (['online', 'wallet'] as $method) {
            $this->assertNotNull($this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => $method])->coupon_id, "booking {$method}");
        }
        $this->assertNotNull($this->bundle($w, 'online')->coupon_id, 'bundle online');

        foreach (['booking' => fn () => $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'cash']), 'bundle' => fn () => $this->bundle($w, 'cash')] as $label => $attempt) {
            try {
                $attempt();
                $this->fail("{$label}: cash accepted");
            } catch (CouponException $e) {
                $this->assertSame(self::MESSAGE, $e->getMessage(), $label);
            }
        }
    }
}
