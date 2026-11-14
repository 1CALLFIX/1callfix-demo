<?php

namespace Tests\Feature\Admin;

use App\Models\Module;
use App\Services\ModuleActivationService;
use App\Support\AdminNav;
use App\Support\ModuleSales;
use App\Support\PartnerPage\PartnerPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-13: "on dashboard top & side menu event, hotel, rental still show pre launch, and soon soon and match
 * the partner page". One source of truth, Admin → Modules: switched on in at least one place = live everywhere (no
 * "pre-launch" in the menu, no "soon" on the dashboard, "Live" + "Apply now" on /partners).
 */
class ModuleLiveLabelsTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_pre_launch_soon_and_partner_cards_follow_admin_modules(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $admin = $this->makeSuperAdmin();
        $menu = fn () => AdminNav::forUser($admin)[0];

        foreach (['events', 'halls'] as $code) {
            $this->assertTrue($menu()[$code === 'halls' ? 'rental' : $code]['preLaunch'], "{$code}: off = pre-launch");
            $this->assertTrue(ModuleSales::modules()[$code === 'halls' ? 'rental' : $code]['prelaunch']);
            $card = collect(PartnerPageData::roles())->firstWhere('code', $code);
            $this->assertFalse($card['live']);
            $this->assertFalse($card['hands_off'], 'Opening soon: waitlist until the early sign-up switch is on.');
        }

        foreach (['events', 'rental'] as $code) {
            Module::where('code', $code)->update(['is_implemented' => true]);
            app(ModuleActivationService::class)->setActive($code, 'franchise', $franchise->id, true);
        }

        $this->assertFalse($menu()['events']['preLaunch']);
        $this->assertFalse($menu()['rental']['preLaunch'], 'The Rental menu follows the one Rental switch.');
        $this->assertFalse(ModuleSales::modules()['events']['prelaunch']);
        $this->assertFalse(ModuleSales::modules()['rental']['prelaunch']);
        foreach (['events', 'halls'] as $code) {
            $card = collect(PartnerPageData::roles())->firstWhere('code', $code);
            $this->assertTrue($card['live']);
            $this->assertTrue($card['hands_off'], "{$code}: Live = Apply now");
        }
        $this->assertTrue($menu()['taxi']['preLaunch'], 'Taxi is still not built.');
    }

    public function test_admin_modules_has_one_rental_row_and_rental_kinds_decide_the_partner_cards(): void
    {
        // Owner 2026-11-14: "why can't we add all rental into 1 module 1 card with all 1 settings".
        $names = Module::orderBy('sort_order')->pluck('name', 'code');
        $this->assertSame('Rental', $names['rental']);
        $this->assertArrayNotHasKey('halls', $names->all());

        // Function halls on by default; Vehicles and Equipment off, so their partner card is hidden until switched on.
        $codes = fn () => collect(PartnerPageData::roles())->pluck('code')->all();
        $this->assertContains('halls', $codes());
        $this->assertNotContains('rental', $codes());
        \App\Models\Setting::set('rental.kind.vehicles', '1');
        $this->assertContains('rental', $codes());
        \App\Models\Setting::set('rental.kind.halls', '0');
        $this->assertNotContains('halls', $codes());
    }
}
