<?php

namespace Tests\Feature\Coupons;

use App\Actions\AdminCancelBookingAction;
use App\Actions\CreateBookingAction;
use App\Exceptions\CouponException;
use App\Livewire\Coupons\Manage;
use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Coupons\CouponAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Coupon engine C2 — Super Admin screens, the daily cap (Q9), HQ-only funding, campaign tag, audit trail and the
 * permission model, plus the thumb rule proven through a coupon that was built on the screen.
 */
class CouponAdminScreensTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fillForm($component, array $overrides = [])
    {
        $fields = array_merge([
            'code' => 'WELCOME50',
            'name' => 'Welcome 50',
            'discountType' => 'flat',
            'value' => '50',
            'perUserLimit' => '1',
            'globalScope' => true, // blank targeting is not allowed (hardening §E)
        ], $overrides);

        foreach ($fields as $k => $v) {
            $component->set($k, $v);
        }

        return $component;
    }

    private function holder(array $perms, string $scope = 'global', ?int $scopeId = null): User
    {
        $user = $this->makeUserWithNoPermissions();
        foreach ($perms as $p) {
            $this->grantPermission($user, $p, $scope, $scopeId);
        }

        return $user;
    }

    // ---------- create / audit / fields ----------

    public function test_super_admin_creates_a_draft_coupon_with_cap_funding_tag_and_city_target_all_audited(): void
    {
        $admin = $this->makeSuperAdmin();
        $city = $this->makeCity();

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon');
        $this->fillForm($c, ['dailyBudget' => '1000', 'totalBudget' => '5000', 'campaignTag' => 'diwali-pilot', 'usageLimit' => '100'])
            ->set('cityIds', [(string) $city->id])
            ->set('customerType', 'new')
            ->call('save')
            ->assertHasNoErrors();

        $coupon = Coupon::firstOrFail();
        $this->assertSame('draft', $coupon->status);
        $this->assertFalse($coupon->is_active, 'A new coupon is never live until approved.');
        $this->assertSame('hq', $coupon->funding_mode);
        $this->assertSame('diwali-pilot', $coupon->campaign_tag);
        $this->assertEquals(1000.00, (float) $coupon->daily_budget);
        $this->assertNull($coupon->franchise_id);
        $this->assertTrue(CouponTarget::where('coupon_id', $coupon->id)->where('target_type', 'city')->where('target_id', $city->id)->exists());
        $this->assertTrue(CouponTarget::where('coupon_id', $coupon->id)->where('target_type', 'customer_type')->exists());

        $log = ActivityLog::where('description', 'coupon.created')->where('subject_id', $coupon->id)->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertArrayHasKey('daily_budget', $log->properties['changes']);
        $this->assertArrayHasKey('campaign_tag', $log->properties['changes']);
    }

    public function test_per_customer_limit_must_be_chosen_and_duplicate_codes_are_refused(): void
    {
        $admin = $this->makeSuperAdmin();
        Coupon::create(['code' => 'TAKEN', 'name' => 'x', 'status' => 'paused', 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1]);

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon');
        $this->fillForm($c, ['perUserLimit' => ''])->call('save')->assertHasErrors(['perUserLimit']);

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon');
        $this->fillForm($c, ['code' => 'taken'])->call('save')->assertHasErrors(['code']);
        $this->assertSame(1, Coupon::count());
    }

    public function test_only_hq_funding_is_accepted_and_caps_are_sane(): void
    {
        $admin = $this->makeSuperAdmin();

        try {
            app(CouponAdminService::class)->save($admin, [
                'code' => 'FUND', 'name' => 'f', 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1, 'funding_mode' => 'franchise',
            ]);
            $this->fail('Franchise funding must be refused until the fund ledger exists.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('funding_mode', $e->errors());
        }

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon');
        $this->fillForm($c, ['totalBudget' => '100', 'dailyBudget' => '500'])->call('save')->assertHasErrors(['dailyBudget']);
    }

    // ---------- permissions ----------

    public function test_a_franchise_scoped_holder_cannot_open_the_screens_and_no_permission_means_403(): void
    {
        $franchise = $this->makeFranchise();
        $scoped = $this->holder(['coupons.view', 'coupons.manage', 'coupons.approve'], 'franchise', $franchise->id);

        Livewire::actingAs($scoped)->test(Manage::class)->assertForbidden();
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Manage::class)->assertForbidden();
    }

    public function test_view_only_cannot_create_manage_cannot_activate_and_each_is_enforced_server_side(): void
    {
        $viewer = $this->holder(['coupons.view']);
        Livewire::actingAs($viewer)->test(Manage::class)->call('newCoupon')->assertForbidden();

        $coupon = Coupon::create(['code' => 'DRAFT1', 'name' => 'd', 'status' => 'draft', 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1]);

        $manager = $this->holder(['coupons.view', 'coupons.manage']);
        Livewire::actingAs($manager)->test(Manage::class)
            ->call('startAction', $coupon->id, 'activate')->assertForbidden();
        Livewire::actingAs($manager)->test(Manage::class)->call('saveSettings')->assertForbidden();

        $approver = $this->holder(['coupons.view', 'coupons.approve']);
        Livewire::actingAs($approver)->test(Manage::class)
            ->call('startAction', $coupon->id, 'activate')
            ->set('actionReason', 'pilot go')
            ->call('confirmAction')
            ->assertHasNoErrors();

        $this->assertSame('active', $coupon->fresh()->status);
        $log = ActivityLog::where('description', 'coupon.status_changed')->firstOrFail();
        $this->assertSame('pilot go', $log->properties['reason']);
        $this->assertSame($approver->id, $log->causer_id);
    }

    public function test_status_changes_require_a_reason(): void
    {
        $admin = $this->makeSuperAdmin();
        $coupon = Coupon::create(['code' => 'R1', 'name' => 'r', 'status' => 'active', 'is_active' => true, 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1]);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('startAction', $coupon->id, 'pause')
            ->set('actionReason', '')
            ->call('confirmAction')
            ->assertHasErrors(['actionReason']);

        $this->assertSame('active', $coupon->fresh()->status);
    }

    public function test_editing_a_live_coupon_needs_approve_but_a_draft_needs_only_manage(): void
    {
        $manager = $this->holder(['coupons.view', 'coupons.manage']);
        $live = Coupon::create(['code' => 'LIVE', 'name' => 'l', 'status' => 'active', 'is_active' => true, 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1]);
        $draft = Coupon::create(['code' => 'DRF', 'name' => 'd', 'status' => 'draft', 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1]);
        foreach ([$live, $draft] as $c) {
            \App\Models\CouponTarget::create(['coupon_id' => $c->id, 'target_type' => 'global', 'operator' => 'include']);
        }

        Livewire::actingAs($manager)->test(Manage::class)->call('edit', $live->id)->assertForbidden();

        Livewire::actingAs($manager)->test(Manage::class)->call('edit', $draft->id)
            ->set('value', '25')->call('save')->assertHasNoErrors();
        $this->assertEquals(25.00, (float) $draft->fresh()->value);
        $log = ActivityLog::where('description', 'coupon.updated')->firstOrFail();
        $this->assertEquals([10.0, 25.0], array_map('floatval', $log->properties['changes']['value']));
    }

    public function test_archive_hides_the_coupon_keeps_its_code_reserved_and_makes_it_unredeemable(): void
    {
        $admin = $this->makeSuperAdmin();
        $coupon = Coupon::create(['code' => 'OLD', 'name' => 'o', 'status' => 'active', 'is_active' => true, 'discount_type' => 'flat', 'value' => 10, 'per_user_limit' => 1]);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('startAction', $coupon->id, 'archive')->set('actionReason', 'campaign over')->call('confirmAction');

        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
        $this->assertTrue(ActivityLog::where('description', 'coupon.archived')->where('subject_id', $coupon->id)->exists());

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon');
        $this->fillForm($c, ['code' => 'old'])->call('save')->assertHasErrors(['code']);
    }

    // ---------- settings ----------

    public function test_settings_are_saved_through_the_audited_path_and_availability_follows(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->assertFalse(\App\Services\Coupons\CouponSettings::available(), 'Coupons are OFF by default.');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('settingEnabled', true)->set('settingHoldMinutes', '30')->call('saveSettings')->assertHasNoErrors();

        $this->assertTrue(\App\Services\Coupons\CouponSettings::available());
        $this->assertSame(2, ActivityLog::where('subject_type', 'setting')->count());

        Livewire::actingAs($admin)->test(Manage::class)->set('settingHoldMinutes', 'abc')->call('saveSettings')->assertHasErrors(['settingHoldMinutes']);
    }

    // ---------- daily cap (Q9) + thumb rule through a screen-built coupon ----------

    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory()); // 500
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');

        return compact('franchise', 'zone', 'service');
    }

    private function book(array $w, User $customer, array $extra = [])
    {
        Queue::fake();
        $address = $this->makeAddress($customer, $w['franchise'], $w['zone']);

        return app(CreateBookingAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $customer->id,
            'service_id' => $w['service']->id, 'address_id' => $address->id, 'payment_method' => 'online',
        ], $extra));
    }

    public function test_daily_cap_rejects_until_midnight_ist_and_releases_give_it_back(): void
    {
        $admin = $this->makeSuperAdmin();
        $w = $this->world();

        // Mid-afternoon IST so "tomorrow" is unambiguous.
        Carbon::setTestNow(Carbon::parse('2026-10-10 09:00:00', 'UTC')); // 14:30 IST
        app(CouponAdminService::class)->save($admin, [
            'code' => 'DAILY', 'name' => 'Daily', 'status' => 'active', 'discount_type' => 'flat', 'value' => 100,
            'per_user_limit' => 5, 'daily_budget' => 150,
        ], [['target_type' => 'global', 'operator' => 'include']]);

        $first = $this->book($w, $this->makeCustomer(), ['coupon_code' => 'DAILY']);
        $this->assertEquals(100.00, (float) $first->coupon_discount_amount);

        try {
            $this->book($w, $this->makeCustomer(), ['coupon_code' => 'DAILY']);
            $this->fail('100 + 100 exceeds the 150 daily cap.');
        } catch (CouponException $e) {
            $this->assertSame('daily_cap_reached', $e->reason, 'The real reason stays internal.');
            $this->assertSame('This coupon cannot be applied to this order.', $e->getMessage(), 'Customers get the generic text (hardening §H).');
        }

        // A release gives the cap back immediately.
        app(AdminCancelBookingAction::class)->execute($first->id, 'test');
        $this->assertSame('released', CouponUsage::firstOrFail()->status);
        $again = $this->book($w, $this->makeCustomer(), ['coupon_code' => 'DAILY']);
        $this->assertNotNull($again->coupon_id);

        // Cap is full again; 00:00 IST (18:30 UTC) rolls it over with no status write.
        try {
            $this->book($w, $this->makeCustomer(), ['coupon_code' => 'DAILY']);
            $this->fail('Cap should be full again.');
        } catch (CouponException $e) {
            $this->assertSame('daily_cap_reached', $e->reason);
        }
        Carbon::setTestNow(Carbon::parse('2026-10-10 18:31:00', 'UTC')); // 00:01 IST next day
        $next = $this->book($w, $this->makeCustomer(), ['coupon_code' => 'DAILY']);
        $this->assertNotNull($next->coupon_id);
        $this->assertSame('active', Coupon::firstOrFail()->status, 'Daily cap is computed, never a status change.');
    }

    public function test_a_coupon_built_on_the_screen_still_rejects_cash_and_has_no_cash_override_anywhere(): void
    {
        $admin = $this->makeSuperAdmin();
        $w = $this->world();

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon');
        $this->fillForm($c, ['code' => 'NOCASH', 'value' => '100'])->call('save')->assertHasNoErrors();
        Livewire::actingAs($admin)->test(Manage::class)
            ->call('startAction', Coupon::firstOrFail()->id, 'activate')->set('actionReason', 'go')->call('confirmAction');

        try {
            $this->book($w, $this->makeCustomer(), ['coupon_code' => 'NOCASH', 'payment_method' => 'cash']);
            $this->fail('Cash + coupon must be rejected server-side.');
        } catch (CouponException $e) {
            $this->assertSame('online_payment_required', $e->reason);
        }
        $this->assertSame(0, CouponUsage::count());

        // The screen exposes no payment-method / cash switch: no such public property, no such setting key written.
        $props = array_keys((new \ReflectionClass(Manage::class))->getDefaultProperties());
        foreach ($props as $p) {
            $this->assertStringNotContainsStringIgnoringCase('cash', $p);
            $this->assertStringNotContainsStringIgnoringCase('paymentMethod', $p);
        }
    }

    public function test_city_targeting_uses_the_city_records_and_blocks_other_cities(): void
    {
        $admin = $this->makeSuperAdmin();
        $w = $this->world();
        $otherCity = \App\Models\City::create([
            'country_id' => $w['franchise']->country_id, 'name' => 'Elsewhere', 'slug' => 'elsewhere', 'is_active' => true,
        ]);

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('newCoupon');
        $this->fillForm($c, ['code' => 'ONLYELSE', 'value' => '100'])->set('cityIds', [(string) $otherCity->id])->call('save');
        Livewire::actingAs($admin)->test(Manage::class)
            ->call('startAction', Coupon::firstOrFail()->id, 'activate')->set('actionReason', 'go')->call('confirmAction');

        $this->expectException(CouponException::class);
        $this->book($w, $this->makeCustomer(), ['coupon_code' => 'ONLYELSE']);
    }
}
