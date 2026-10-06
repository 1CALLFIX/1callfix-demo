<?php

namespace Tests\Feature\Coupons;

use App\Actions\CreateBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Exceptions\BookingContextMismatchException;
use App\Exceptions\CouponException;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\City;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Coupons\CouponAdminService;
use App\Services\Coupons\CouponService;
use App\Services\Coupons\PromotionContext;
use App\Services\Coupons\PromotionResult;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 1CF-COUPON-HARDENING-003 (docs/COUPON_HARDENING_003.md) — domain-layer hardening of the coupon engine:
 * explicit scope (E), server-authoritative franchise/zone/module (F, G), one generic customer message (H),
 * null-vs-zero limits (K), and the targeting guarantees C2.2 will build on. "Nellore" is the base city of
 * makeFranchiseTree(); "Guntur" is a second city + franchise + zone built here.
 */
class CouponHardeningTest extends TestCase
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

        return compact('country', 'city', 'franchise', 'zone', 'service', 'customer', 'address');
    }

    private function guntur(array $w, string $status = 'active'): array
    {
        $city = City::create(['country_id' => $w['country']->id, 'name' => 'Guntur '.Str::random(4), 'slug' => 'guntur-'.Str::random(4), 'is_active' => true]);
        $franchise = Franchise::create([
            'name' => 'Guntur F', 'slug' => 'guntur-f-'.Str::random(4), 'city' => 'Guntur', 'country_id' => $w['country']->id, 'city_id' => $city->id,
            'commission_model' => 'revenue_share', 'commission_value' => 10, 'platform_fee_percent' => 5, 'status' => $status,
        ]);
        $zone = Zone::create(['franchise_id' => $franchise->id, 'name' => 'GZ', 'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]], 'is_active' => true, 'default_dispatch_radius_km' => 8]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        return compact('city', 'franchise', 'zone', 'customer', 'address');
    }

    /** A coupon with NO scope rows at all (what a legacy / hand-inserted coupon looks like). */
    private function bareCoupon(array $a = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'SAVE100', 'name' => 's', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 5,
        ], $a));
    }

    private function target(Coupon $c, string $type, ?int $id = null, string $op = 'include', ?array $params = null): void
    {
        CouponTarget::create(['coupon_id' => $c->id, 'target_type' => $type, 'target_id' => $id, 'operator' => $op, 'params' => $params]);
    }

    private function globalCoupon(array $a = []): Coupon
    {
        $c = $this->bareCoupon($a);
        $this->target($c, 'global');

        return $c;
    }

    private function book(array $w, array $who, array $extra = []): Booking
    {
        Queue::fake();

        return app(CreateBookingAction::class)->execute(array_merge([
            'franchise_id' => $who['franchise']->id, 'zone_id' => $who['zone']->id, 'customer_id' => $who['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $who['address']->id, 'payment_method' => 'online',
        ], $extra));
    }

    private function ctx(array $who, string $module = 'service', string $pm = 'online', string $code = 'SAVE100'): PromotionContext
    {
        return new PromotionContext($module, $who['franchise']->id, $who['franchise']->city_id, $who['zone']->id, $who['customer'], $pm,
            [['line_ref' => '1', 'category_id' => null, 'subcategory_id' => null, 'service_id' => 1, 'line_total' => 500.0]], $code,
            countryId: $who['franchise']->country_id);
    }

    // ───────────────────────── R1 / R2: server-authoritative city + franchise ─────────────────────────

    public function test_1_nellore_coupon_is_rejected_for_a_guntur_booking(): void
    {
        $w = $this->world();
        $g = $this->guntur($w);
        $c = $this->bareCoupon();
        $this->target($c, 'city', $w['city']->id);

        $this->assertNotNull($this->book($w, $w, ['coupon_code' => 'SAVE100'])->coupon_id, 'Nellore booking gets it.');

        $this->expectException(CouponException::class);
        $this->book($w, $g, ['coupon_code' => 'SAVE100']);
    }

    public function test_2_client_supplied_nellore_franchise_cannot_override_a_guntur_address(): void
    {
        $w = $this->world();
        $g = $this->guntur($w);
        $c = $this->bareCoupon();
        $this->target($c, 'city', $w['city']->id);

        // Guntur customer + Guntur address, but the caller claims the Nellore franchise and zone.
        try {
            $this->book($w, ['franchise' => $w['franchise'], 'zone' => $w['zone'], 'customer' => $g['customer'], 'address' => $g['address']], ['coupon_code' => 'SAVE100']);
            $this->fail('A franchise that does not own the address was accepted.');
        } catch (BookingContextMismatchException) {
            $this->assertSame(0, Booking::count());
            $this->assertSame(0, CouponUsage::count());
        }
    }

    public function test_2b_a_zone_from_another_franchise_is_rejected(): void
    {
        $w = $this->world();
        $g = $this->guntur($w);

        $this->expectException(BookingContextMismatchException::class);
        $this->book($w, ['franchise' => $w['franchise'], 'zone' => $g['zone'], 'customer' => $w['customer'], 'address' => $w['address']]);
    }

    public function test_2c_an_address_that_belongs_to_another_customer_is_rejected(): void
    {
        $w = $this->world();
        $other = $this->makeCustomer();

        $this->expectException(BookingContextMismatchException::class);
        $this->book($w, ['franchise' => $w['franchise'], 'zone' => $w['zone'], 'customer' => $other, 'address' => $w['address']]);
    }

    public function test_2d_bundle_children_with_a_forged_franchise_are_rejected_and_nothing_is_written(): void
    {
        $w = $this->world();
        $g = $this->guntur($w);
        $this->globalCoupon();
        Queue::fake();

        try {
            app(CreateBookingBundleAction::class)->execute([
                'customer_id' => $g['customer']->id, 'payment_method' => 'online', 'idempotency_key' => null, 'request_fingerprint' => 'fp-forged', 'coupon_code' => 'SAVE100',
                'children' => [['service_id' => $w['service']->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'address_id' => $g['address']->id]],
            ]);
            $this->fail('Forged bundle child accepted.');
        } catch (BookingContextMismatchException) {
            $this->assertSame(0, BookingBundle::count());
            $this->assertSame(0, Booking::count());
            $this->assertSame(0, CouponUsage::count());
        }
    }

    public function test_2e_the_booking_row_carries_the_server_derived_franchise_and_zone(): void
    {
        $w = $this->world();
        $b = $this->book($w, $w);

        $this->assertSame($w['address']->franchise_id, $b->franchise_id);
        $this->assertSame($w['address']->zone_id, $b->zone_id);
    }

    // ───────────────────────── R3 / R4 / R5: module support ─────────────────────────

    public function test_3_a_services_coupon_is_rejected_for_a_parcel_order(): void
    {
        $w = $this->world();
        $this->globalCoupon();

        $r = app(CouponService::class)->validate($this->ctx($w, 'parcel'));
        $this->assertFalse($r->eligible);
        $this->assertSame('module_not_connected', $r->reasonCode);
    }

    public function test_4_a_future_module_coupon_is_rejected_until_that_module_is_connected(): void
    {
        $w = $this->world();
        $this->globalCoupon(['module' => 'parcel']);

        $r = app(CouponService::class)->validate($this->ctx($w, 'parcel'));
        $this->assertFalse($r->eligible);
        $this->assertSame('module_not_connected', $r->reasonCode);

        // The registry, not the presence of a context, decides.
        config(['coupons.connected_modules' => ['service', 'parcel']]);
        $r = app(CouponService::class)->validate($this->ctx($w, 'parcel'));
        $this->assertSame('module_not_enabled', $r->reasonCode, 'Connected but ModuleActivationService says parcel is not implemented/enabled.');
    }

    public function test_5_a_coupon_is_rejected_when_the_module_is_disabled_for_the_franchise_and_no_usage_row_is_written(): void
    {
        $w = $this->world();
        $this->globalCoupon();
        app(ModuleActivationService::class)->setActive(Modules::SERVICE, 'franchise', $w['franchise']->id, false);

        $r = app(CouponService::class)->validate($this->ctx($w));
        $this->assertFalse($r->eligible);
        $this->assertSame('module_not_enabled', $r->reasonCode);

        try {
            $this->book($w, $w, ['coupon_code' => 'SAVE100']);
            $this->fail('should be refused');
        } catch (ModuleNotActiveException) {
            $this->assertSame(0, CouponUsage::count());
            $this->assertSame(0, Booking::count());
        }
    }

    // ───────────────────────── R6 / R7: scope must be explicit ─────────────────────────

    public function test_6_a_coupon_with_no_targeting_is_rejected_by_the_engine_and_by_the_admin_service(): void
    {
        $w = $this->world();
        $this->bareCoupon();

        $r = app(CouponService::class)->validate($this->ctx($w));
        $this->assertFalse($r->eligible);
        $this->assertSame('no_scope', $r->reasonCode, 'Blank targeting never means everywhere.');

        $admin = $this->makeSuperAdmin();
        try {
            app(CouponAdminService::class)->save($admin, [
                'code' => 'EVERY', 'name' => 'e', 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1,
                'module' => 'service', 'status' => 'draft',
            ], []);
            $this->fail('A scope-less coupon was saved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('targets', $e->errors());
        }
        $this->assertNull(Coupon::where('code', 'EVERY')->first());
    }

    public function test_7_explicit_global_scope_works_and_needs_coupons_approve(): void
    {
        $w = $this->world();
        $this->globalCoupon();
        $this->assertTrue(app(CouponService::class)->validate($this->ctx($w))->eligible);

        $data = ['code' => 'GLOB', 'name' => 'g', 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1, 'module' => 'service', 'status' => 'draft'];
        $targets = [['target_type' => 'global', 'operator' => 'include']];

        $manager = $this->makeUserWithNoPermissions();
        $this->grantPermission($manager, 'coupons.manage');
        try {
            app(CouponAdminService::class)->save($manager, $data, $targets);
            $this->fail('manage alone created a global coupon');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('targets', $e->errors());
        }

        $approver = $this->makeUserWithNoPermissions();
        $this->grantPermission($approver, 'coupons.manage');
        $this->grantPermission($approver, 'coupons.approve');
        $saved = app(CouponAdminService::class)->save($approver, $data, $targets);
        $this->assertTrue($saved->targets()->where('target_type', 'global')->exists());
    }

    // ───────────────────────── R8: one generic message ─────────────────────────

    public function test_8_every_non_redeemable_reason_returns_the_identical_message_and_shape(): void
    {
        $w = $this->world();
        $g = $this->guntur($w);
        $svc = app(CouponService::class);
        $generic = PromotionResult::GENERIC_MESSAGE;
        $this->assertSame('This coupon cannot be applied to this order.', $generic);

        $this->globalCoupon(['code' => 'PAUSED', 'status' => 'paused', 'is_active' => false]);
        $this->globalCoupon(['code' => 'EXPIRED', 'valid_until' => now()->subDay()]);
        $this->globalCoupon(['code' => 'FUTURE', 'valid_from' => now()->addDay()]);
        $city = $this->bareCoupon(['code' => 'NELLORE']);
        $this->target($city, 'city', $w['city']->id);
        $this->globalCoupon(['code' => 'USED0', 'usage_limit' => 0]);
        $this->globalCoupon(['code' => 'BUDGET0', 'total_budget' => 0.01]);
        $this->globalCoupon(['code' => 'DAILY0', 'daily_budget' => 0.01]);
        $mine = $this->bareCoupon(['code' => 'MINE']);
        $this->target($mine, 'customer', $w['customer']->id);
        $this->globalCoupon(['code' => 'MINORDER', 'min_order_value' => 100000]);
        $this->bareCoupon(['code' => 'NOSCOPE']);

        $cases = [
            'invalid_code' => $svc->validate($this->ctx($g, 'service', 'online', 'NOSUCH')),
            'inactive' => $svc->validate($this->ctx($g, 'service', 'online', 'PAUSED')),
            'expired' => $svc->validate($this->ctx($g, 'service', 'online', 'EXPIRED')),
            'not_started' => $svc->validate($this->ctx($g, 'service', 'online', 'FUTURE')),
            'wrong_city' => $svc->validate($this->ctx($g, 'service', 'online', 'NELLORE')),
            'wrong_customer' => $svc->validate($this->ctx($g, 'service', 'online', 'MINE')),
            'usage_limit' => $svc->validate($this->ctx($w, 'service', 'online', 'USED0')),
            'budget' => $svc->validate($this->ctx($w, 'service', 'online', 'BUDGET0')),
            'daily_cap' => $svc->validate($this->ctx($w, 'service', 'online', 'DAILY0')),
            'wrong_module' => $svc->validate($this->ctx($w, 'parcel', 'online', 'PAUSED')),
            'min_order' => $svc->validate($this->ctx($w, 'service', 'online', 'MINORDER')),
            'no_scope' => $svc->validate($this->ctx($w, 'service', 'online', 'NOSCOPE')),
        ];

        $reasons = [];
        foreach ($cases as $label => $r) {
            $this->assertFalse($r->eligible, $label);
            $this->assertSame($generic, $r->message, "{$label}: customer message must be generic.");
            $this->assertSame([], $r->lineAllocations, $label);
            $reasons[$label] = $r->reasonCode;
        }
        // The real reason is still there for logs, audit and admin diagnostics — and they differ.
        $this->assertSame('invalid_code', $reasons['invalid_code']);
        $this->assertSame('daily_cap_reached', $reasons['daily_cap']);
        $this->assertGreaterThan(8, count(array_unique($reasons)));

        // Same response shape on the exception the booking path throws.
        $payloads = [];
        foreach (['NOSUCH', 'PAUSED', 'EXPIRED'] as $code) {
            try {
                $this->book($w, $w, ['coupon_code' => $code]);
            } catch (CouponException $e) {
                $payloads[] = $e->customerPayload();
            }
        }
        $this->assertCount(3, $payloads);
        $this->assertSame($payloads[0], $payloads[1]);
        $this->assertSame($payloads[1], $payloads[2]);
        $this->assertSame(['message' => $generic], $payloads[0]);
    }

    // ───────────────────────── R22-28: null vs zero, enforced in the service ─────────────────────────

    private function base(string $code): array
    {
        return ['code' => $code, 'name' => 'n', 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1, 'module' => 'service', 'status' => 'draft'];
    }

    private function globalTargets(): array
    {
        return [['target_type' => 'global', 'operator' => 'include']];
    }

    #[DataProvider('capFields')]
    public function test_22_to_28_null_is_unlimited_zero_is_invalid_positive_is_valid_at_the_service_layer(string $field): void
    {
        $admin = $this->makeSuperAdmin();
        $svc = app(CouponAdminService::class);

        $null = $svc->save($admin, $this->base('N'.$field) + [$field => null], $this->globalTargets());
        $this->assertNull($null->{$field});

        $pos = $svc->save($admin, $this->base('P'.$field) + [$field => 50], $this->globalTargets());
        $this->assertEquals(50, $pos->{$field});

        foreach ([0, '0', '0.00'] as $zero) {
            try {
                $svc->save($admin, $this->base('Z'.$field) + [$field => $zero], $this->globalTargets());
                $this->fail("{$field}={$zero} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertNull(Coupon::where('code', 'Z'.$field)->first());
    }

    public static function capFields(): array
    {
        return ['usage limit' => ['usage_limit'], 'total budget' => ['total_budget'], 'daily cap' => ['daily_budget']];
    }

    public function test_28b_a_zero_cap_already_in_the_database_never_redeems(): void
    {
        $w = $this->world();
        foreach (['usage_limit' => 'U0', 'total_budget' => 'T0', 'daily_budget' => 'D0'] as $field => $code) {
            $this->globalCoupon(['code' => $code, $field => 0]);
            $this->assertFalse(app(CouponService::class)->validate($this->ctx($w, 'service', 'online', $code))->eligible, "{$field}=0 must not redeem.");
        }
    }

    // ───────────────────────── R39-42: targeting guarantees C2.2 builds on ─────────────────────────

    public function test_39_a_coupon_targeted_to_one_franchise_is_rejected_in_another(): void
    {
        $w = $this->world();
        $g = $this->guntur($w);
        $c = $this->bareCoupon();
        $this->target($c, 'franchise', $w['franchise']->id);

        $this->assertTrue(app(CouponService::class)->validate($this->ctx($w))->eligible);
        $this->assertFalse(app(CouponService::class)->validate($this->ctx($g))->eligible);
    }

    public function test_40_a_coupon_targeted_to_one_customer_is_rejected_for_another_customer(): void
    {
        $w = $this->world();
        $g = $this->guntur($w);
        $c = $this->bareCoupon();
        $this->target($c, 'customer', $w['customer']->id);

        $this->assertTrue(app(CouponService::class)->validate($this->ctx($w))->eligible);
        $this->assertFalse(app(CouponService::class)->validate($this->ctx($g))->eligible);
    }

    public function test_41_all_live_franchises_reaches_a_live_franchise_even_one_added_after_the_coupon(): void
    {
        $w = $this->world();
        $this->globalCoupon();
        $g = $this->guntur($w, 'active'); // created AFTER the coupon: evaluated at redemption time

        $this->assertTrue(app(CouponService::class)->validate($this->ctx($w))->eligible);
        $this->assertTrue(app(CouponService::class)->validate($this->ctx($g))->eligible);
    }

    public function test_42_all_live_franchises_never_reaches_a_franchise_that_is_not_live_and_honours_the_exclude_list(): void
    {
        $w = $this->world();
        $c = $this->globalCoupon();
        $pending = $this->guntur($w, 'pending_setup');
        $inactive = $this->guntur($w, 'inactive');

        foreach ([$pending, $inactive] as $f) {
            $r = app(CouponService::class)->validate($this->ctx($f));
            $this->assertFalse($r->eligible);
            $this->assertSame('franchise_not_live', $r->reasonCode);
        }

        $this->target($c, 'franchise', $w['franchise']->id, 'exclude');
        $this->assertFalse(app(CouponService::class)->validate($this->ctx($w))->eligible, 'Exclude list wins over global.');
    }
}
