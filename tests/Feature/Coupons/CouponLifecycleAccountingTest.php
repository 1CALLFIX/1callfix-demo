<?php

namespace Tests\Feature\Coupons;

use App\Actions\AdminCancelBookingAction;
use App\Actions\CreateBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Exceptions\CouponException;
use App\Jobs\ServiceMatchingJob;
use App\Livewire\Coupons\Manage;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Cancellation\InterimChargeCalculator;
use App\Services\Coupons\CouponAdminService;
use App\Services\Coupons\CouponHoldSweepService;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 1CF-COUPON-HARDENING-003 §H diagnostics, §K/§N/§R (31-38): the lifecycle and accounting guarantees —
 * pause/archive keep an unpaid hold, a confirmed booking never gives budget back while an UNPAID hold does,
 * a bundle is ONE usage, and a coupon never touches the visit / inspection charge. Probes from
 * ArchProbeTest.php.txt that were not already covered by CouponHardeningTest are committed here.
 * (Tests 29/30 — commission and payout identical with a coupon — already exist in CouponEngineTest as
 * test_commission_and_payout_are_identical_with_and_without_a_coupon and are not duplicated.)
 */
class CouponLifecycleAccountingTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory());
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');

        return compact('franchise', 'zone', 'service', 'customer', 'address');
    }

    private function coupon(array $a = []): Coupon
    {
        $c = Coupon::create(array_merge(['code' => 'SAVE100', 'name' => 's', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 9], $a));
        CouponTarget::create(['coupon_id' => $c->id, 'target_type' => 'global', 'operator' => 'include']);

        return $c;
    }

    private function book(array $w, array $extra = [], ?\App\Models\User $customer = null): Booking
    {
        Queue::fake();
        $customer ??= $w['customer'];
        $address = $customer->id === $w['customer']->id ? $w['address'] : $this->makeAddress($customer, $w['franchise'], $w['zone']);

        return app(CreateBookingAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $customer->id,
            'service_id' => $w['service']->id, 'address_id' => $address->id, 'payment_method' => 'online',
        ], $extra));
    }

    // ───────────────────────── H: the real reason stays internal ─────────────────────────

    public function test_h_the_precise_reason_is_logged_for_diagnostics_while_the_customer_sees_the_generic_text(): void
    {
        $w = $this->world();
        $this->coupon(['code' => 'OLD', 'valid_until' => now()->subDay()]);
        Log::spy();

        try {
            $this->book($w, ['coupon_code' => 'OLD']);
            $this->fail('expired coupon accepted');
        } catch (CouponException $e) {
            $this->assertSame('expired', $e->reason);
            $this->assertSame(['message' => 'This coupon cannot be applied to this order.'], $e->customerPayload());
        }

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return $message === 'coupon.rejected' && ($context['reason'] ?? null) === 'expired' && isset($context['customer_id'], $context['detail']);
        })->once();
    }

    // ───────────────────────── 31 / 32: pause and archive never cancel an unpaid hold ─────────────────────────

    public function test_31_and_32_pause_and_archive_preserve_an_existing_unpaid_hold_and_it_stays_payable(): void
    {
        $w = $this->world();
        $c = $this->coupon();
        $b = $this->book($w, ['coupon_code' => 'SAVE100']);
        $svc = app(CouponAdminService::class);
        $admin = $this->makeSuperAdmin();

        $svc->setStatus($admin, $c, 'paused', 'test');
        $this->assertSame('pending', $b->fresh()->status, '31: pause keeps the hold.');
        $this->assertSame('reserved', CouponUsage::firstOrFail()->status);

        $svc->archive($admin, $c->fresh(), 'test');
        $this->assertSame('pending', $b->fresh()->status, '32: archive keeps the hold.');
        $this->assertSame('reserved', CouponUsage::firstOrFail()->status);
        $this->assertEquals(100.00, (float) $b->fresh()->coupon_discount_amount, 'Existing financials are untouched (§N).');

        // …and it is still payable inside the hold, then dispatches.
        Payment::create(['booking_id' => $b->id, 'purpose' => 'booking', 'amount' => $b->amountPayable(), 'gateway' => 'razorpay', 'gateway_order_id' => 'order_P', 'status' => 'pending']);
        Queue::fake();
        app(RazorpayWebhookHandler::class)->handleCaptured(['payload' => ['payment' => ['entity' => ['id' => 'pay_P', 'order_id' => 'order_P', 'amount' => 40000, 'currency' => 'INR']]]]);
        $this->assertSame('paid', $b->fresh()->payment_status);
        Queue::assertPushed(ServiceMatchingJob::class);

        // A hold on a coupon that gets paused still expires on its own clock.
        $c2 = $this->coupon(['code' => 'OTHER']);
        $b2 = $this->book($w, ['coupon_code' => 'OTHER']);
        $svc->setStatus($admin, $c2, 'paused', 'test');
        $this->travel(31)->minutes();
        $this->assertSame(1, app(CouponHoldSweepService::class)->sweep());
        $this->assertSame('cancelled', $b2->fresh()->status);
    }

    // ───────────────────────── 33 / 34: what cancellation gives back (same class on purpose) ─────────────────────────

    public function test_33_cancelling_a_confirmed_booking_does_not_restore_budget_or_daily_cap(): void
    {
        $w = $this->world();
        $this->coupon(['total_budget' => 100, 'daily_budget' => 100]);
        $b = $this->book($w, ['coupon_code' => 'SAVE100']);
        $b->status = 'completed';
        $b->price_final = $b->price_quoted;
        $b->save();
        $this->assertSame('confirmed', CouponUsage::firstOrFail()->status);

        $b->status = 'cancelled';
        $b->save();
        $this->assertSame('confirmed', CouponUsage::firstOrFail()->status, 'A realised benefit is never handed back.');

        try {
            $this->book($w, ['coupon_code' => 'SAVE100'], $this->makeCustomer());
            $this->fail('budget/daily cap were wrongly restored');
        } catch (CouponException $e) {
            $this->assertContains($e->reason, ['budget_exhausted', 'exhausted', 'daily_cap_reached']);
        }
    }

    public function test_34_cancelling_an_unpaid_hold_releases_the_reservation_and_restores_budget_and_daily_cap(): void
    {
        $w = $this->world();
        $c = $this->coupon(['total_budget' => 100, 'daily_budget' => 100]);
        $b = $this->book($w, ['coupon_code' => 'SAVE100']);
        $this->assertSame('reserved', CouponUsage::firstOrFail()->status);

        // Budget and daily cap are fully used while the hold stands.
        try {
            $this->book($w, ['coupon_code' => 'SAVE100'], $this->makeCustomer());
            $this->fail('second reservation should not fit');
        } catch (CouponException) {
        }

        app(AdminCancelBookingAction::class)->execute($b->id, 'customer changed mind');

        $this->assertSame('released', CouponUsage::firstOrFail()->status);
        $this->assertEquals(0.0, (float) $c->fresh()->reserved_amount, 'Total budget is back.');
        $this->assertNotNull($this->book($w, ['coupon_code' => 'SAVE100'], $this->makeCustomer())->coupon_id, 'Budget and daily cap are usable again.');
    }

    // ───────────────────────── 35 / 36: bundle accounting ─────────────────────────

    public function test_35_and_36_a_bundle_consumes_one_usage_and_is_counted_once_in_budget_and_daily_cap(): void
    {
        $w = $this->world();
        $second = $this->makeService($this->makeCategory());
        $c = $this->coupon(['total_budget' => 150, 'daily_budget' => 150, 'usage_limit' => 1]);
        Queue::fake();

        $bundle = app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $w['customer']->id, 'payment_method' => 'online', 'idempotency_key' => null, 'request_fingerprint' => 'fp-acc', 'coupon_code' => 'SAVE100',
            'children' => [
                ['service_id' => $w['service']->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'address_id' => $w['address']->id],
                ['service_id' => $second->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'address_id' => $w['address']->id],
            ],
        ]);

        $this->assertSame(1, CouponUsage::count(), '35: one usage row for the whole bundle.');
        $usage = CouponUsage::firstOrFail();
        $this->assertEquals(100.00, (float) $usage->discount_applied, '36: applied once on the bundle total, not per child.');
        $this->assertEquals(100.00, (float) $bundle->fresh()->coupon_discount_amount);
        $this->assertEquals(100.00, (float) Booking::where('booking_bundle_id', $bundle->id)->sum('coupon_discount_amount'), 'Child shares add up to the one discount.');
        $this->assertEquals(100.00, (float) $c->fresh()->reserved_amount);
        $this->assertSame('exhausted', $c->fresh()->status, 'usage_limit 1 is consumed once.');

        // Daily cap: 100 spent today, so another 100 does not fit under 150.
        $c->update(['status' => 'active', 'is_active' => true, 'usage_limit' => null, 'total_budget' => null]);
        $this->expectException(CouponException::class);
        $this->book($w, ['coupon_code' => 'SAVE100'], $this->makeCustomer());
    }

    // ───────────────────────── 37 / 38: visit / inspection charge ─────────────────────────

    private function visitFee(): void
    {
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '149');
    }

    public function test_37_a_completed_service_has_no_visit_or_inspection_charge_even_with_a_coupon(): void
    {
        $this->visitFee();
        $w = $this->world();
        $this->coupon();
        $b = $this->book($w, ['coupon_code' => 'SAVE100']);
        $b->statusHistory()->create(['status' => 'in_progress', 'changed_by' => $w['customer']->id, 'note' => 'work started', 'changed_at' => now()]);
        $b->status = 'completed';
        $b->price_final = $b->price_quoted;
        $b->save();

        $calc = app(InterimChargeCalculator::class)->calculate($b->fresh());
        $this->assertTrue($calc['work_started']);
        $this->assertEquals(0.0, $calc['visit_fee'], 'Work was done: the visit charge is never added.');
        $this->assertEquals(400.00, $b->fresh()->amountPayable(), '500 service - 100 coupon; nothing else is added.');
    }

    public function test_38_a_coupon_never_reduces_the_visit_inspection_charge(): void
    {
        $this->visitFee();
        $w = $this->world();
        $this->coupon();
        $plain = $this->book($w);
        $withCoupon = $this->book($w, ['coupon_code' => 'SAVE100'], $this->makeCustomer());

        $calc = app(InterimChargeCalculator::class);
        $a = $calc->calculate($plain->fresh());
        $b = $calc->calculate($withCoupon->fresh());

        $this->assertFalse($b['work_started']);
        $this->assertEquals(149.0, $a['visit_fee']);
        $this->assertEquals($a['visit_fee'], $b['visit_fee'], 'The no-work charge is the same with or without a coupon.');
        $this->assertEquals($a['job_price'], $b['job_price'], 'The coupon is not part of the base the charge is judged on.');
    }

    // ───────────────────────── form-level scope (probe 14) ─────────────────────────

    public function test_the_form_refuses_empty_targeting_and_global_needs_the_approval_permission(): void
    {
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon')
            ->set('code', 'EVERY')->set('name', 'e')->set('value', '10')->set('perUserLimit', '1')->set('globalScope', false)->call('save')
            ->assertHasErrors(['targets']);
        $this->assertNull(Coupon::where('code', 'EVERY')->first());

        $manager = $this->makeUserWithNoPermissions();
        $this->grantPermission($manager, 'coupons.view');
        $this->grantPermission($manager, 'coupons.manage');
        Livewire::actingAs($manager)->test(Manage::class)->call('newCoupon')
            ->set('code', 'GLOBM')->set('name', 'g')->set('value', '10')->set('perUserLimit', '1')->set('globalScope', true)->call('save')
            ->assertHasErrors(['targets']);
        $this->assertNull(Coupon::where('code', 'GLOBM')->first(), 'manage alone cannot create a global coupon.');

        Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon')
            ->set('code', 'GLOBA')->set('name', 'g')->set('value', '10')->set('perUserLimit', '1')->set('globalScope', true)->call('save')
            ->assertHasNoErrors();
        $this->assertTrue(Coupon::where('code', 'GLOBA')->firstOrFail()->targets()->where('target_type', 'global')->exists());
    }
}
