<?php

namespace Tests\Feature\Marketplace;

use App\Models\Module;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 2026-10-29 live look-through: Modules showed E-commerce as "Not yet built", so it could never be switched on.
 * The migration marks it built; it stays off everywhere until an admin activates it. Food / grocery / pharmacy stay
 * unbuilt (owner: Phase 1 is Commerce only).
 */
class CommerceModuleImplementedTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    public function test_commerce_is_built_but_off_until_an_admin_switches_it_on(): void
    {
        $this->assertTrue((bool) Module::where('code', Modules::COMMERCE)->value('is_implemented'));
        foreach ([Modules::FOOD, Modules::GROCERY, Modules::PHARMACY] as $code) {
            $this->assertFalse((bool) Module::where('code', $code)->value('is_implemented'), "{$code} stays unbuilt");
        }

        [, , $franchise] = $this->makeFranchiseTree();
        $modules = app(ModuleActivationService::class);
        $this->assertFalse($modules->isActive(Modules::COMMERCE, ['franchise_id' => $franchise->id]), 'off by default');
        $this->assertFalse($modules->isActiveAnywhere(Modules::COMMERCE));

        $modules->setActive(Modules::COMMERCE, 'franchise', $franchise->id, true);
        $this->assertTrue($modules->isActive(Modules::COMMERCE, ['franchise_id' => $franchise->id]), 'now it can be switched on');
    }
}
