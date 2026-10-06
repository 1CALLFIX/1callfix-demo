<?php

namespace Tests\Feature\Coupons;

use App\Livewire\Coupons\Manage;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Coupons\CouponSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * C3 items 5 and 6 — per-surface coupon-entry toggles (wizard, cart, checkout, bundles) and the entry rate
 * limit are Super Admin settings, enforced in the domain call (not only the screen). Nothing is hardcoded:
 * the owner's values (10 per minute per customer, 30 per minute per IP) are the setting defaults and are edited
 * here.
 */
class CouponEntrySettingsTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function approver(): User
    {
        $user = $this->makeUserWithNoPermissions();
        foreach (['coupons.view', 'coupons.manage', 'coupons.approve'] as $p) {
            $this->grantPermission($user, $p, 'global', null);
        }

        return $user;
    }

    public function test_surfaces_default_to_on_behind_the_master_switch_and_can_be_switched_off(): void
    {
        foreach (CouponSettings::SURFACES as $surface) {
            $this->assertTrue(CouponSettings::surfaceEnabled($surface), "{$surface} defaults on");
        }

        CouponSettings::saveEntryControls($this->makeSuperAdmin(), ['cart' => false], null, null, null);

        $this->assertFalse(CouponSettings::surfaceEnabled('cart'));
        $this->assertTrue(CouponSettings::surfaceEnabled('wizard'));
        $this->assertFalse(CouponSettings::surfaceEnabled('not-a-surface'), 'An unknown surface is never enabled.');
    }

    public function test_rate_limit_defaults_are_the_owners_values_and_are_editable(): void
    {
        $this->assertSame(10, CouponSettings::attemptsPerCustomer());
        $this->assertSame(30, CouponSettings::attemptsPerIp());
        $this->assertSame(60, CouponSettings::attemptWindowSeconds());

        CouponSettings::saveEntryControls($this->makeSuperAdmin(), [], 5, 12, 120);

        $this->assertSame(5, CouponSettings::attemptsPerCustomer());
        $this->assertSame(12, CouponSettings::attemptsPerIp());
        $this->assertSame(120, CouponSettings::attemptWindowSeconds());
    }

    public function test_only_a_super_admin_can_change_them_and_every_change_is_audited(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('surfaceCart', false)->set('attemptsPerCustomer', '7')->set('attemptsPerIp', '20')->set('attemptWindowSeconds', '60')
            ->call('saveEntryControls')->assertHasNoErrors();

        $this->assertFalse(CouponSettings::surfaceEnabled('cart'));
        $this->assertSame(7, CouponSettings::attemptsPerCustomer());
        $this->assertGreaterThanOrEqual(3, ActivityLog::where('subject_type', 'setting')->where('causer_id', $admin->id)->count());

        $approver = $this->approver();
        Livewire::actingAs($approver)->test(Manage::class)
            ->set('surfaceWizard', false)->call('saveEntryControls')->assertForbidden();
        $this->assertTrue(CouponSettings::surfaceEnabled('wizard'));

        try {
            CouponSettings::saveEntryControls($approver, ['wizard' => false], 1, 1, 1);
            $this->fail('approve-only holder changed an entry control');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(7, CouponSettings::attemptsPerCustomer());
    }

    public function test_invalid_rate_limit_values_are_rejected_not_stored(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('attemptsPerCustomer', '0')->set('attemptsPerIp', 'abc')->set('attemptWindowSeconds', '-5')
            ->call('saveEntryControls')->assertHasErrors(['attemptsPerCustomer', 'attemptsPerIp', 'attemptWindowSeconds']);

        $this->assertNull(Setting::get('coupons.rate_limit.customer_attempts'));
    }
}
