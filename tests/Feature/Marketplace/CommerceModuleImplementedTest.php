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
        // Food is built too since 2026-11-03 (same step, its own migration).
        foreach ([Modules::COMMERCE, Modules::FOOD] as $code) {
            $this->assertTrue((bool) Module::where('code', $code)->value('is_implemented'), "{$code} is built");
        }
        foreach ([Modules::GROCERY, Modules::PHARMACY] as $code) {
            $this->assertFalse((bool) Module::where('code', $code)->value('is_implemented'), "{$code} stays unbuilt");
        }

        [, , $franchise] = $this->makeFranchiseTree();
        $modules = app(ModuleActivationService::class);
        foreach ([Modules::COMMERCE, Modules::FOOD] as $code) {
            $this->assertFalse($modules->isActive($code, ['franchise_id' => $franchise->id]), "{$code} off by default");
            $this->assertFalse($modules->isActiveAnywhere($code));

            $modules->setActive($code, 'franchise', $franchise->id, true);
            $this->assertTrue($modules->isActive($code, ['franchise_id' => $franchise->id]), "{$code} can now be switched on");
        }
    }
}
