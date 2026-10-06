<?php

namespace Tests\Feature\Coupons;

use App\Livewire\Coupons\Manage;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Coupons\CouponSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * 1CF-COUPON-HARDENING-003 §J, §L (docs/COUPON_HARDENING_003.md). Global coupon settings (coupons.enabled,
 * coupons.unpaid_hold_minutes, and later the rate limit / per-surface toggles) are Super Admin ONLY:
 * coupons.approve does not grant them. Enforced in the domain call (CouponSettings::save), not only the screen.
 */
class CouponGlobalSettingsTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function holder(array $perms, string $scope = 'global', ?int $scopeId = null): User
    {
        $user = $this->makeUserWithNoPermissions();
        foreach ($perms as $p) {
            $this->grantPermission($user, $p, $scope, $scopeId);
        }

        return $user;
    }

    public function test_19_super_admin_can_change_global_coupon_settings_and_it_is_audit_logged(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('settingEnabled', true)->set('settingHoldMinutes', '30')->call('saveSettings')->assertHasNoErrors();

        $this->assertTrue(CouponSettings::available());
        $logs = ActivityLog::where('subject_type', 'setting')->get();
        $this->assertSame(2, $logs->count(), 'One audit row per changed key.');
        $this->assertTrue($logs->every(fn ($l) => $l->causer_id === $admin->id));
    }

    public function test_20_coupons_approve_alone_cannot_change_global_settings(): void
    {
        $approver = $this->holder(['coupons.view', 'coupons.approve']);

        Livewire::actingAs($approver)->test(Manage::class)
            ->set('settingEnabled', true)->set('settingHoldMinutes', '30')->call('saveSettings')->assertForbidden();

        $this->assertNull(Setting::get('coupons.enabled'));
        $this->assertNull(Setting::get('coupons.unpaid_hold_minutes'));
        $this->assertSame(0, ActivityLog::where('subject_type', 'setting')->count());

        // Domain layer protects itself, not just the screen.
        try {
            CouponSettings::save($approver, true, '30');
            $this->fail('approve-only holder changed a global setting');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull(Setting::get('coupons.enabled'));
    }

    public function test_21_coupons_manage_view_only_and_franchise_scoped_holders_all_get_403(): void
    {
        $franchise = $this->makeFranchise();

        $cases = [
            'manage' => $this->holder(['coupons.view', 'coupons.manage']),
            'manage+approve' => $this->holder(['coupons.view', 'coupons.manage', 'coupons.approve']),
            'view only' => $this->holder(['coupons.view']),
        ];
        foreach ($cases as $label => $user) {
            Livewire::actingAs($user)->test(Manage::class)
                ->set('settingEnabled', true)->set('settingHoldMinutes', '30')->call('saveSettings')->assertForbidden();
            $this->assertNull(Setting::get('coupons.enabled'), $label);
        }

        // A franchise-scoped holder cannot even open the screen.
        $scoped = $this->holder(['coupons.view', 'coupons.manage', 'coupons.approve'], 'franchise', $franchise->id);
        Livewire::actingAs($scoped)->test(Manage::class)->assertForbidden();
        try {
            CouponSettings::save($scoped, true, '30');
            $this->fail('franchise-scoped holder changed a global setting');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull(Setting::get('coupons.enabled'));
    }

    public function test_the_settings_form_is_shown_only_to_a_super_admin(): void
    {
        $approver = $this->holder(['coupons.view', 'coupons.approve']);
        Livewire::actingAs($approver)->test(Manage::class)->assertDontSee('Save settings')->assertSee('Super Admin');

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)->assertSee('Save settings');
    }

    // ───────────────────────── L: visit / inspection wording ─────────────────────────

    public function test_l_the_coupon_screen_carries_exactly_one_visit_charge_sentence(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSee('Visit and inspection charges are separate from coupon discounts.')
            ->assertDontSee('never reduces the visit charge')
            ->assertDontSee('never touches extra work');
    }
}
