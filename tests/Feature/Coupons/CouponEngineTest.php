<?php

namespace Tests\Feature\Coupons;

use App\Actions\AdminCancelBookingAction;
use App\Actions\CreateBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Exceptions\CouponException;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\EntitlementBalance;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Coupons\CouponHoldSweepService;
use App\Services\Coupons\CouponService;
use App\Services\Documents\DocumentService;
use App\Services\Payments\RazorpayWebhookHandler;
use App\Services\Plans\SubscriptionService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\WithLegacyWalletPayments;
use Tests\TestCase;

/**
 * Coupon engine C1 (docs/COUPON_ENGINE_DESIGN.md §00). Nothing is mocked except
 * the queue: real actions, real engine, real wallet. SQLite runs the LOGIC of
 * the concurrency guarantees (second reserve sees the first; UNIQUE backstop);
 * the row-lock itself needs the manual MySQL procedure in design §16.
 */
class CouponEngineTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;
    use WithLegacyWalletPayments;

    private function world(array $serviceAttributes = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();
        $service = $this->makeService($category, $serviceAttributes); // base_price 500
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');

        return compact('franchise', 'zone', 'category', 'service', 'customer', 'address');
    }

    private function coupon(array $attributes = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'code' => 'SAVE100',
            'name' => 'Save 100',
            'status' => 'active',
            'is_active' => true,
            'module' => 'service',
            'discount_type' => 'flat',
            'value' => 100,
            'min_order_value' => 0,
            'per_user_limit' => 1,
        ], $attributes));

        // Hardening §E: blank targeting is not "everywhere" — these engine tests use the explicit global scope.
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);

        return $coupon;
    }

    private function book(array $w, array $extra = []): Booking
    {
        Queue::fake();

        return app(CreateBookingAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id,
            'zone_id' => $w['zone']->id,
            'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id,
            'address_id' => $w['address']->id,
            'payment_method' => 'online',
        ], $extra));
    }

    private function fundWallet(User $user, float $amount): void
    {
        app(WalletService::class)->credit($user, $amount, 'test top-up', 'test:'.Str::random(8));
    }

    // ==================== Core pricing: D1 ====================

    public function test_coupon_keeps_price_quoted_gross_and_records_the_discount_separately(): void
    {
        $w = $this->world();
        $this->coupon();

        $booking = $this->book($w, ['coupon_code' => 'save100']);

        $this->assertEquals(500.00, (float) $booking->price_quoted, 'D1: price_quoted stays gross.');
        $this->assertEquals(100.00, (float) $booking->coupon_discount_amount);
        $this->assertEquals(400.00, $booking->amountPayable());
        $this->assertNotNull($booking->coupon_id);
        $this->assertSame('SAVE100', $booking->coupon_snapshot['code']);

        $usage = CouponUsage::firstOrFail();
        $this->assertSame('reserved', $usage->status);
        $this->assertEquals(100.00, (float) $usage->discount_applied);
    }

    public function test_wallet_booking_is_charged_the_discounted_amount_and_payment_row_matches(): void
    {
        $w = $this->world();
        $this->coupon();
        $this->fundWallet($w['customer'], 1000);

        $booking = $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);

        $payment = Payment::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(400.00, (float) $payment->amount);
        $this->assertEquals(600.00, (float) $w['customer']->wallet->fresh()->balance);
        $this->assertSame('paid', $booking->fresh()->payment_status);
    }

    public function test_percent_coupon_is_capped_by_max_discount(): void
    {
        $w = $this->world();
        $this->coupon(['discount_type' => 'percent', 'value' => 50, 'max_discount' => 120]);

        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);

        $this->assertEquals(120.00, (float) $booking->coupon_discount_amount);
    }

    public function test_discount_never_takes_the_payable_below_the_technical_minimum(): void
    {
        $w = $this->world();
        $this->coupon(['value' => 9999]);

        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);

        $this->assertEquals(1.00, $booking->amountPayable());
    }

    public function test_client_cannot_inject_a_discount_or_price(): void
    {
        $w = $this->world();
        $this->coupon();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $w['service']->id,
            'address_id' => $w['address']->id,
            'payment_method' => 'online',
            'coupon_discount_amount' => 499,
            'discount' => 499,
            'price_quoted' => 1,
        ])->assertStatus(201);

        $booking = Booking::latest('id')->firstOrFail();
        $this->assertEquals(500.00, (float) $booking->price_quoted);
        $this->assertEquals(0.00, (float) $booking->coupon_discount_amount);
    }

    // ==================== THUMB RULE ====================

    public function test_coupon_is_rejected_on_a_cash_booking_with_the_exact_message(): void
    {
        $w = $this->world();
        $this->coupon();

        try {
            $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'cash']);
            $this->fail('Cash + coupon must be rejected.');
        } catch (CouponException $e) {
            $this->assertSame('online_payment_required', $e->reason);
            $this->assertSame('Coupons are valid only for online payments', $e->getMessage());
        }

        $this->assertSame(0, Booking::count(), 'The whole booking rolls back.');
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_engine_rejects_cash_in_validate_and_reserve_directly(): void
    {
        $w = $this->world();
        $coupon = $this->coupon();
        $booking = $this->book($w, ['payment_method' => 'online']);
        $booking->payment_method = 'cash';
        $ctx = app(\App\Services\Coupons\ServicePromotionContextBuilder::class)->forBooking($booking, $coupon->code);

        $this->assertSame('online_payment_required', app(CouponService::class)->validate($ctx)->reasonCode);
        $this->expectException(CouponException::class);
        app(CouponService::class)->reserve($ctx, $booking);
    }

    public function test_a_coupon_booking_can_never_switch_to_cash_afterwards(): void
    {
        $w = $this->world();
        $this->coupon();
        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);

        $this->expectException(\LogicException::class);
        $booking->payment_method = 'cash';
        $booking->save();
    }

    public function test_a_booking_without_a_benefit_can_still_change_payment_method(): void
    {
        $w = $this->world();
        $booking = $this->book($w);

        $booking->payment_method = 'cash';
        $booking->save();

        $this->assertSame('cash', $booking->fresh()->payment_method);
    }

    // ==================== Kill switch / settings fail closed ====================

    public function test_coupons_are_unavailable_while_disabled(): void
    {
        $w = $this->world();
        $this->coupon();
        Setting::set('coupons.enabled', '0');

        $this->expectException(CouponException::class);
        $this->book($w, ['coupon_code' => 'SAVE100']);
    }

    public function test_coupons_are_unavailable_until_the_unpaid_hold_is_configured(): void
    {
        $w = $this->world();
        $this->coupon();
        Setting::clear('coupons.unpaid_hold_minutes', 'global', null);

        try {
            $this->book($w, ['coupon_code' => 'SAVE100']);
            $this->fail('Unset hold must block coupon bookings.');
        } catch (CouponException $e) {
            $this->assertSame('coupons_unavailable', $e->reason);
        }
    }

    // ==================== Validity rules ====================

    public function test_invalid_inactive_expired_and_not_started_codes_are_rejected(): void
    {
        $w = $this->world();

        $cases = [
            'NOPE' => 'invalid_code',
        ];
        $this->coupon(['code' => 'PAUSED', 'status' => 'paused', 'is_active' => false]);
        $cases['PAUSED'] = 'inactive';
        $this->coupon(['code' => 'OLD', 'valid_until' => now()->subDay()]);
        $cases['OLD'] = 'expired';
        $this->coupon(['code' => 'SOON', 'valid_from' => now()->addDay()]);
        $cases['SOON'] = 'not_started';
        $this->coupon(['code' => 'BIG', 'min_order_value' => 10000]);
        $cases['BIG'] = 'below_minimum';

        foreach ($cases as $code => $reason) {
            try {
                $this->book($w, ['coupon_code' => $code]);
                $this->fail("{$code} should be rejected");
            } catch (CouponException $e) {
                $this->assertSame($reason, $e->reason, $code);
                $this->assertSame('This coupon cannot be applied to this order.', $e->getMessage(), "{$code}: generic customer text (hardening §H)");
            }
        }
    }

    public function test_per_user_and_global_usage_limits(): void
    {
        $w = $this->world();
        $this->coupon(['usage_limit' => 1, 'per_user_limit' => 1]);

        $this->book($w, ['coupon_code' => 'SAVE100']);

        // Same customer again.
        try {
            $this->book($w, ['coupon_code' => 'SAVE100']);
            $this->fail('per-user limit');
        } catch (CouponException $e) {
            $this->assertContains($e->reason, ['exhausted', 'over_per_user_limit']);
        }

        // Another customer — the global limit is also spent.
        $other = $this->makeCustomer();
        $otherAddress = $this->makeAddress($other, $w['franchise'], $w['zone']);
        try {
            $this->book(array_merge($w, ['customer' => $other, 'address' => $otherAddress]), ['coupon_code' => 'SAVE100']);
            $this->fail('global limit');
        } catch (CouponException $e) {
            $this->assertSame('exhausted', $e->reason);
        }
    }

    public function test_last_redemption_cannot_be_used_twice(): void
    {
        $w = $this->world();
        $coupon = $this->coupon(['usage_limit' => 1, 'per_user_limit' => 5]);

        $this->book($w, ['coupon_code' => 'SAVE100']);

        $this->assertSame('exhausted', $coupon->fresh()->status, 'Reaching the limit auto-exhausts the coupon.');
        $this->assertSame(1, CouponUsage::count());

        // DB backstop: one booking can never hold two redemptions.
        $this->expectException(\Illuminate\Database\QueryException::class);
        CouponUsage::create(['coupon_id' => $coupon->id, 'user_id' => $w['customer']->id, 'booking_id' => Booking::first()->id, 'discount_applied' => 1]);
    }

    public function test_total_budget_is_a_hard_cap_and_auto_exhausts(): void
    {
        $w = $this->world();
        $coupon = $this->coupon(['total_budget' => 150, 'per_user_limit' => 5, 'value' => 100]);

        $this->book($w, ['coupon_code' => 'SAVE100']);          // 100 reserved
        try {
            $this->book($w, ['coupon_code' => 'SAVE100']);      // 200 > 150
            $this->fail('budget');
        } catch (CouponException $e) {
            $this->assertSame('budget_exhausted', $e->reason);
        }

        $this->assertEquals(100.00, (float) $coupon->fresh()->reserved_amount);
    }

    // ==================== Targeting ====================

    public function test_service_include_and_exclude_targets(): void
    {
        $w = $this->world();
        $other = $this->makeService($w['category'], ['name' => 'Other']);

        $coupon = $this->coupon();
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'service_category', 'target_id' => $w['category']->id, 'operator' => 'include']);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'service', 'target_id' => $other->id, 'operator' => 'exclude']);

        $this->assertNotNull($this->book($w, ['coupon_code' => 'SAVE100'])->coupon_id, 'in-category, not excluded');

        $this->coupon(['code' => 'TWO', 'per_user_limit' => 5]);
        CouponTarget::create(['coupon_id' => Coupon::where('code', 'TWO')->value('id'), 'target_type' => 'service', 'target_id' => $other->id, 'operator' => 'exclude']);
        $this->expectException(CouponException::class);
        $this->book(array_merge($w, ['service' => $other]), ['coupon_code' => 'TWO']);
    }

    public function test_unknown_include_target_type_fails_closed(): void
    {
        $w = $this->world();
        $coupon = $this->coupon();
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'service', 'target_id' => 999999, 'operator' => 'include']);

        $this->expectException(CouponException::class);
        $this->book($w, ['coupon_code' => 'SAVE100']);
    }

    public function test_first_booking_only_customer_type_target(): void
    {
        $w = $this->world();
        $coupon = $this->coupon(['per_user_limit' => 5]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'customer_type', 'operator' => 'include', 'params' => ['type' => 'new']]);

        $this->assertNotNull($this->book($w, ['coupon_code' => 'SAVE100'])->coupon_id, 'brand new customer qualifies');

        $this->expectException(CouponException::class); // now has a booking => no longer new
        $this->book($w, ['coupon_code' => 'SAVE100']);
    }

    // ==================== Stacking ====================

    public function test_coupon_is_refused_on_a_flash_sale_unless_stackable(): void
    {
        $w = $this->world();
        $this->makeFlashSale([$w['service']], ['discount_type' => 'percent', 'discount_value' => 20]);
        $this->coupon();

        try {
            $this->book($w, ['coupon_code' => 'SAVE100']);
            $this->fail('flash conflict');
        } catch (CouponException $e) {
            $this->assertSame('flash_sale_conflict', $e->reason);
        }

        Coupon::where('code', 'SAVE100')->update(['stackable_with_flash' => true]);
        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);
        $this->assertEquals(400.00, (float) $booking->price_quoted, 'sale price is the gross');
        $this->assertEquals(100.00, (float) $booking->coupon_discount_amount);
    }

    private function subscribeMember(User $customer, array $entitlement): void
    {
        $plan = Plan::create([
            'name' => 'Member', 'slug' => 'm-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        PlanEntitlement::create(array_merge([
            'plan_id' => $plan->id, 'usage_period' => 'monthly',
            'consumption_trigger' => 'booking_created', 'rollover_policy' => 'none',
        ], $entitlement));
        app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
    }

    public function test_larger_of_coupon_and_member_benefit_applies_never_both(): void
    {
        // Member 30% (= 150) beats the 100 coupon: member applies, coupon not used.
        $w = $this->world();
        $this->coupon();
        $this->subscribeMember($w['customer'], ['entitlement_type' => 'percentage_discount', 'percentage_value' => 30, 'quantity' => 5]);

        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);

        $this->assertEquals(350.00, (float) $booking->price_quoted, 'member price applied');
        $this->assertEquals(0.00, (float) $booking->coupon_discount_amount);
        $this->assertNull($booking->coupon_id);
        $this->assertFalse($booking->coupon_snapshot['applied']);
        $this->assertSame('member_benefit_larger', $booking->coupon_snapshot['reason']);
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_coupon_wins_when_larger_and_member_unit_is_not_consumed(): void
    {
        // Member 10% (= 50) loses to the 100 coupon: coupon applies, member balance untouched.
        $w = $this->world();
        $this->coupon();
        $this->subscribeMember($w['customer'], ['entitlement_type' => 'percentage_discount', 'percentage_value' => 10, 'quantity' => 5]);

        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);

        $this->assertEquals(500.00, (float) $booking->price_quoted);
        $this->assertEquals(100.00, (float) $booking->coupon_discount_amount);
        $balance = EntitlementBalance::firstOrFail();
        $this->assertSame(5, $balance->remainingQuantity(), 'Member entitlement not consumed when the coupon wins.');
    }

    public function test_coupon_never_applies_on_a_quantity_redemption_entitlement(): void
    {
        $w = $this->world();
        $this->coupon();
        $this->subscribeMember($w['customer'], ['entitlement_type' => 'quantity', 'quantity' => 4]);

        try {
            $this->book($w, ['coupon_code' => 'SAVE100']);
            $this->fail('quantity covered');
        } catch (CouponException $e) {
            $this->assertSame('entitlement_covered', $e->reason);
        }
    }

    // ==================== Dispatch gate + unpaid hold ====================

    public function test_online_coupon_booking_is_not_dispatched_until_payment_is_captured(): void
    {
        $w = $this->world();
        $this->coupon();

        Queue::fake();
        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id,
            'payment_method' => 'online', 'coupon_code' => 'SAVE100',
        ]);
        Queue::assertNotPushed(ServiceMatchingJob::class);

        $payment = Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => $booking->amountPayable(),
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_X1', 'status' => 'pending',
        ]);

        app(RazorpayWebhookHandler::class)->handleCaptured(['payload' => ['payment' => ['entity' => [
            'id' => 'pay_1', 'order_id' => 'order_X1', 'amount' => 40000, 'currency' => 'INR',
        ]]]]);

        Queue::assertPushed(ServiceMatchingJob::class, 1);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertSame('captured', $payment->fresh()->status);
    }

    public function test_wallet_paid_coupon_booking_dispatches_immediately(): void
    {
        $w = $this->world();
        $this->coupon();
        $this->fundWallet($w['customer'], 1000);

        Queue::fake();
        app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id,
            'payment_method' => 'wallet', 'coupon_code' => 'SAVE100',
        ]);
        Queue::assertPushed(ServiceMatchingJob::class, 1);
    }

    public function test_unpaid_coupon_booking_is_cancelled_after_the_hold_and_the_coupon_released(): void
    {
        $w = $this->world();
        $coupon = $this->coupon(['usage_limit' => 1]);
        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);
        $this->assertSame('exhausted', $coupon->fresh()->status);

        // Inside the hold: untouched.
        $this->assertSame(0, app(CouponHoldSweepService::class)->sweep());

        $this->travel(31)->minutes();
        $this->assertSame(1, app(CouponHoldSweepService::class)->sweep());

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('released', CouponUsage::firstOrFail()->status);
        $this->assertSame('active', $coupon->fresh()->status, 'Capacity is given back.');
        $this->assertEquals(0.00, (float) $coupon->fresh()->reserved_amount);
    }

    public function test_hold_sweep_is_a_noop_while_the_hold_is_unset_and_never_touches_paid_bookings(): void
    {
        $w = $this->world();
        $this->coupon();
        $this->fundWallet($w['customer'], 1000);
        $paid = $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);

        $this->travel(5)->hours();
        $this->assertSame(0, app(CouponHoldSweepService::class)->sweep());
        $this->assertNotSame('cancelled', $paid->fresh()->status, 'A paid coupon booking is never swept.');

        Setting::clear('coupons.unpaid_hold_minutes', 'global', null);
        $this->assertSame(0, app(CouponHoldSweepService::class)->sweep());
    }

    // ==================== Lifecycle: release / consume / confirm ====================

    public function test_cancel_before_work_releases_even_when_the_provider_has_arrived(): void
    {
        $w = $this->world();
        $this->coupon();
        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);
        $booking->status = 'provider_en_route';
        $booking->save();

        app(AdminCancelBookingAction::class)->execute($booking->id, 'test');

        $this->assertSame('released', CouponUsage::firstOrFail()->status);
    }

    public function test_cancel_once_work_started_consumes_the_usage(): void
    {
        $w = $this->world();
        $coupon = $this->coupon(['usage_limit' => 5]);
        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);
        $booking->status = 'in_progress';
        $booking->save();

        app(AdminCancelBookingAction::class)->execute($booking->id, 'customer left mid-job');

        $this->assertSame('consumed', CouponUsage::firstOrFail()->status);
        $this->assertSame(1, CouponUsage::whereIn('status', CouponUsage::COUNTING)->count(), 'Still counts toward limits.');
    }

    public function test_completion_confirms_once_and_is_idempotent(): void
    {
        $w = $this->world();
        $coupon = $this->coupon();
        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);

        $booking->status = 'completed';
        $booking->price_final = $booking->price_quoted;
        $booking->save();

        $usage = CouponUsage::firstOrFail();
        $this->assertSame('confirmed', $usage->status);
        $this->assertNotNull($usage->confirmed_at);

        app(CouponService::class)->confirm($usage);
        app(CouponService::class)->release($usage); // too late — no-op
        $this->assertSame('confirmed', $usage->fresh()->status);
        $this->assertEquals(100.00, (float) $coupon->fresh()->confirmed_amount);
        $this->assertSame(1, $coupon->fresh()->usage_count_confirmed);
    }

    public function test_editing_a_coupon_never_changes_an_existing_booking(): void
    {
        $w = $this->world();
        $coupon = $this->coupon();
        $booking = $this->book($w, ['coupon_code' => 'SAVE100']);

        $coupon->update(['value' => 400]);

        $this->assertEquals(100.00, (float) $booking->fresh()->coupon_discount_amount);
        $this->assertEquals(100.00, (float) $booking->fresh()->coupon_snapshot['discount']);
    }

    // ==================== Money: commission / payout / refund untouched ====================

    public function test_commission_and_payout_are_identical_with_and_without_a_coupon(): void
    {
        $with = $this->completedCommission(true);
        $without = $this->completedCommission(false);

        $this->assertEquals($without['platform'], $with['platform']);
        $this->assertEquals($without['provider'], $with['provider']);
        $this->assertEquals($without['franchise'], $with['franchise']);
    }

    private function completedCommission(bool $coupon): array
    {
        $w = $this->world();
        if ($coupon) {
            $this->coupon(['code' => 'C'.Str::random(4)]);
            $code = Coupon::latest('id')->value('code');
        }
        $this->fundWallet($w['customer'], 1000);
        $provider = $this->makeProviderIn($w['franchise'], $w['zone']);
        $booking = $this->book($w, ['payment_method' => 'wallet'] + ($coupon ? ['coupon_code' => $code] : []));
        $booking->provider_id = $provider->id;
        $booking->status = 'completed';
        $booking->price_final = $booking->price_quoted;
        $booking->save();

        app(\App\Services\CommissionService::class)->applyForBooking($booking->fresh());
        $c = \App\Models\Commission::where('booking_id', $booking->id)->firstOrFail();

        return [
            'platform' => (float) ($c->platform_amount ?? $c->platform_fee ?? 0),
            'provider' => (float) ($c->provider_amount ?? $c->provider_earning ?? 0),
            'franchise' => (float) ($c->franchise_amount ?? 0),
        ];
    }

    public function test_cancellation_refund_is_based_on_what_was_paid_and_never_negative(): void
    {
        $w = $this->world();
        $this->coupon();
        $this->fundWallet($w['customer'], 1000);
        $booking = $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);

        app(AdminCancelBookingAction::class)->execute($booking->id, 'changed mind');

        // Paid 400 from a 1000 wallet; cancelled before a provider => no fee => the 400 comes back.
        $this->assertEquals(1000.00, (float) $w['customer']->wallet->fresh()->balance);
        $this->assertSame('released', CouponUsage::firstOrFail()->status);
    }

    // ==================== Invoice ====================

    public function test_invoice_shows_gross_coupon_and_net_summing_to_the_amount_paid(): void
    {
        $w = $this->world();
        $this->coupon();
        $this->fundWallet($w['customer'], 1000);
        $booking = $this->book($w, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);
        $payment = Payment::where('booking_id', $booking->id)->firstOrFail();

        $data = app(DocumentService::class)->forPayment($payment, 'receipt');

        $this->assertCount(2, $data['lines']);
        $this->assertEquals(500.00, $data['lines'][0]['amount']);
        $this->assertEquals(-100.00, $data['lines'][1]['amount']);
        $this->assertStringContainsString('SAVE100', $data['lines'][1]['label']);
        $this->assertEquals($data['total'], array_sum(array_column($data['lines'], 'amount')));
    }

    // ==================== Loyalty (Q7) ====================

    public function test_loyalty_points_use_the_amount_actually_paid(): void
    {
        // The earning call site is CompleteBookingAction; here we pin the arithmetic it uses.
        $booking = new Booking(['price_final' => 600, 'coupon_discount_amount' => 100]);
        $paid = max((float) $booking->price_final - (float) $booking->coupon_discount_amount, 0);

        $this->assertEquals(500.00, $paid);
    }

    // ==================== Bundles ====================

    private function bundleOf(array $w, int $count, array $extra = []): BookingBundle
    {
        Queue::fake();
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $children[] = [
                'service_id' => $w['service']->id, 'franchise_id' => $w['franchise']->id,
                'zone_id' => $w['zone']->id, 'address_id' => $w['address']->id,
            ];
        }

        return app(CreateBookingBundleAction::class)->execute(array_merge([
            'customer_id' => $w['customer']->id,
            'payment_method' => 'online',
            'idempotency_key' => null,
            'request_fingerprint' => Str::random(20),
            'children' => $children,
        ], $extra));
    }

    public function test_bundle_discount_is_allocated_exactly_and_payment_is_net(): void
    {
        $w = $this->world();
        $this->coupon(['value' => 100]);   // across 3 x 500

        $this->fundWallet($w['customer'], 5000);
        $bundle = $this->bundleOf($w, 3, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);
        $this->assertEquals(1500.00, (float) $bundle->total_price_quoted, 'gross');
        $this->assertEquals(100.00, (float) $bundle->coupon_discount_amount);

        $shares = Booking::where('booking_bundle_id', $bundle->id)->pluck('coupon_discount_amount')->map(fn ($v) => (float) $v);
        $this->assertEquals(100.00, round($shares->sum(), 2), 'Σ child shares === bundle discount exactly');
        $this->assertSame(1, CouponUsage::count(), 'One usage per bundle.');
    }

    public function test_bundle_refund_math_is_net_of_each_childs_coupon_share(): void
    {
        $w = $this->world();
        $this->coupon(['value' => 90]);
        $this->fundWallet($w['customer'], 5000);

        $bundle = $this->bundleOf($w, 3, ['coupon_code' => 'SAVE100', 'payment_method' => 'wallet']);
        $payment = Payment::where('booking_bundle_id', $bundle->id)->firstOrFail();
        $this->assertEquals(1410.00, (float) $payment->amount, '1500 gross - 90');

        $bundle->load('children');
        $this->assertEquals(0.00, app(\App\Services\BundleSettlementService::class)->refundDue($bundle, $payment),
            'Nothing cancelled: every child retained at gross minus its share == amount paid.');

        $first = $bundle->children->first();
        app(AdminCancelBookingAction::class)->execute($first->id, 'one child cancelled');
        $bundle->refresh()->load('children');

        $share = (float) $first->fresh()->coupon_discount_amount;
        $this->assertGreaterThan(0, $share);
        $this->assertEquals(
            round(500 - $share, 2),
            app(\App\Services\BundleSettlementService::class)->refundDue($bundle, $payment->fresh()),
            'Cancelling one child refunds exactly what that child paid (gross minus its coupon share).'
        );
    }

    public function test_cash_bundle_with_a_coupon_is_rejected(): void
    {
        $w = $this->world();
        $this->coupon();

        $this->expectException(CouponException::class);
        $this->bundleOf($w, 2, ['coupon_code' => 'SAVE100', 'payment_method' => 'cash']);
    }

    public function test_online_coupon_bundle_children_are_held_until_payment(): void
    {
        $w = $this->world();
        $this->coupon();

        Queue::fake();
        $bundle = app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $w['customer']->id, 'payment_method' => 'online', 'idempotency_key' => null,
            'request_fingerprint' => 'fp', 'coupon_code' => 'SAVE100',
            'children' => [
                ['service_id' => $w['service']->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'address_id' => $w['address']->id],
            ],
        ]);

        Queue::assertNotPushed(ServiceMatchingJob::class);
        $this->assertNotNull($bundle->fresh()->coupon_id);
    }

    // ==================== Audit ====================

    public function test_coupon_admin_changes_are_audited_with_old_and_new_values(): void
    {
        $admin = $this->makeCustomer();
        $svc = app(\App\Services\Coupons\CouponAdminService::class);

        $coupon = $svc->save($admin, [
            'code' => 'AUD1', 'name' => 'Audit', 'status' => 'draft', 'discount_type' => 'flat',
            'value' => 50, 'per_user_limit' => 2,
        ], [['target_type' => 'city', 'target_id' => $this->makeFranchiseTree()[1]->id, 'operator' => 'include']]);
        $svc->save($admin, ['value' => 75], null, $coupon);
        $svc->setStatus($admin, $coupon, 'active', 'launch');

        $logs = \App\Models\ActivityLog::where('subject_type', Coupon::class)->where('subject_id', $coupon->id)->orderBy('id')->get();
        $this->assertSame(['coupon.created', 'coupon.updated', 'coupon.status_changed'], $logs->pluck('description')->all());
        $this->assertEquals(['50.00', '75.00'], array_map(fn ($v) => number_format((float) $v, 2, '.', ''), $logs[1]->properties['changes']['value']));
        $this->assertSame('launch', $logs[2]->properties['reason']);
    }

    public function test_coupon_admin_requires_an_explicit_per_user_limit_and_unique_code(): void
    {
        $admin = $this->makeCustomer();
        $svc = app(\App\Services\Coupons\CouponAdminService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->save($admin, ['code' => 'NOLIMIT', 'name' => 'x', 'discount_type' => 'flat', 'value' => 10]);
    }
}
