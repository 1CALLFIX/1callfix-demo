<?php

namespace Tests\Feature\Coupons;

use App\Actions\CreateBookingAction;
use App\Livewire\Coupons\Manage;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Setting;
use App\Services\Coupons\CouponAdminService;
use App\Services\Coupons\CouponService;
use App\Services\Coupons\PromotionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * D1 (docs/COUPON_HARDENING_FINAL.md) — a new-customers-only coupon needs the explicit "Global / all eligible
 * scope" choice (coupons.approve). Empty targeting never means global. Enforced in the form, CouponAdminService
 * and the engine; a client-supplied global flag is never trusted on its own.
 */
class NewCustomerGlobalScopeTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    private const NEW = ['target_type' => 'customer_type', 'operator' => 'include', 'params' => ['type' => 'new']];

    private const GLOBAL = ['target_type' => 'global', 'operator' => 'include'];

    private function data(string $code = 'WELCOME'): array
    {
        return ['code' => $code, 'name' => 'w', 'discount_type' => 'flat', 'value' => 50, 'per_user_limit' => 1, 'module' => 'service', 'status' => 'draft'];
    }

    private function approver()
    {
        $u = $this->makeUserWithNoPermissions();
        $this->grantPermission($u, 'coupons.manage');
        $this->grantPermission($u, 'coupons.approve');

        return $u;
    }

    private function rejects(callable $fn): void
    {
        try {
            $fn();
            $this->fail('A new-customer coupon without Global scope was saved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('targets', $e->errors());
        }
    }

    public function test_new_customer_coupon_with_explicit_global_is_allowed(): void
    {
        $saved = app(CouponAdminService::class)->save($this->approver(), $this->data(), [self::NEW, self::GLOBAL]);

        $this->assertTrue($saved->targets()->where('target_type', 'global')->exists());
        $this->assertTrue($saved->targets()->where('target_type', 'customer_type')->exists());
    }

    public function test_new_customer_coupon_without_global_is_rejected_even_with_a_city_scope(): void
    {
        $city = $this->makeCity();

        $this->rejects(fn () => app(CouponAdminService::class)->save($this->approver(), $this->data(), [
            self::NEW, ['target_type' => 'city', 'target_id' => $city->id, 'operator' => 'include'],
        ]));
        $this->assertSame(0, Coupon::count());
    }

    public function test_new_customer_coupon_with_empty_targeting_is_rejected(): void
    {
        $this->rejects(fn () => app(CouponAdminService::class)->save($this->approver(), $this->data(), [self::NEW]));
        $this->rejects(fn () => app(CouponAdminService::class)->save($this->approver(), $this->data('W2'), []));
    }

    public function test_a_forged_global_flag_from_someone_without_approve_is_rejected(): void
    {
        $manager = $this->makeUserWithNoPermissions();
        $this->grantPermission($manager, 'coupons.manage');

        $this->rejects(fn () => app(CouponAdminService::class)->save($manager, $this->data(), [self::NEW, self::GLOBAL]));
        $this->assertSame(0, Coupon::count());
    }

    public function test_adding_the_new_customer_audience_to_an_existing_coupon_needs_approve_and_global(): void
    {
        $city = $this->makeCity();
        $cityRow = ['target_type' => 'city', 'target_id' => $city->id, 'operator' => 'include'];
        $manager = $this->makeUserWithNoPermissions();
        $this->grantPermission($manager, 'coupons.manage');
        $coupon = app(CouponAdminService::class)->save($manager, $this->data('PLAIN'), [$cityRow]);

        $this->rejects(fn () => app(CouponAdminService::class)->save($manager, [], [$cityRow, self::NEW], $coupon));
    }

    public function test_the_form_rejects_new_customer_without_global_and_accepts_it_with_global(): void
    {
        $admin = $this->makeSuperAdmin();
        $city = $this->makeCity();
        $fill = fn ($c) => $c->call('newCoupon')->set('code', 'FORM1')->set('name', 'f')->set('discountType', 'flat')->set('value', '50')
            ->set('minOrderValue', '0')->set('perUserLimit', '1')->set('customerType', 'new');

        $fill(Livewire::actingAs($admin)->test(Manage::class))->set('cityIds', [(string) $city->id])->call('save')->assertHasErrors('targets');
        $this->assertSame(0, Coupon::count());

        $fill(Livewire::actingAs($admin)->test(Manage::class))->set('globalScope', true)->call('save')->assertHasNoErrors();
        $this->assertSame(1, Coupon::count());
    }

    private function legacyNewCoupon(array $targets): Coupon
    {
        $c = Coupon::create(['code' => 'WELCOME', 'name' => 'w', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 50, 'min_order_value' => 0, 'per_user_limit' => 5]);
        foreach ($targets as $t) {
            CouponTarget::create(['coupon_id' => $c->id] + $t);
        }

        return $c;
    }

    private function ctx(array $w): PromotionContext
    {
        return new PromotionContext('service', $w['franchise']->id, $w['franchise']->city_id, $w['zone']->id, $w['customer'], 'online',
            [['line_ref' => '1', 'category_id' => null, 'subcategory_id' => null, 'service_id' => $w['service']->id, 'line_total' => 500.0]], 'WELCOME',
            countryId: $w['franchise']->country_id);
    }

    private function world(): array
    {
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory());
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');

        return compact('city', 'franchise', 'zone', 'service', 'customer', 'address');
    }

    public function test_engine_rejects_a_stored_new_customer_coupon_that_lacks_global_scope(): void
    {
        $w = $this->world();
        $this->legacyNewCoupon([self::NEW, ['target_type' => 'city', 'target_id' => $w['city']->id, 'operator' => 'include']]);

        $r = app(CouponService::class)->validate($this->ctx($w));

        $this->assertFalse($r->eligible);
        $this->assertSame('no_scope', $r->reasonCode);
    }

    public function test_engine_accepts_a_genuinely_new_customer_on_a_global_new_customer_coupon(): void
    {
        $w = $this->world();
        $this->legacyNewCoupon([self::NEW, self::GLOBAL]);

        $this->assertTrue(app(CouponService::class)->validate($this->ctx($w))->eligible);
    }

    public function test_engine_rejects_an_existing_customer_on_a_global_new_customer_coupon(): void
    {
        $w = $this->world();
        $this->legacyNewCoupon([self::NEW, self::GLOBAL]);
        Queue::fake();
        app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online',
        ]);

        $r = app(CouponService::class)->validate($this->ctx($w));

        $this->assertFalse($r->eligible);
        $this->assertSame('not_targeted', $r->reasonCode);
    }
}
