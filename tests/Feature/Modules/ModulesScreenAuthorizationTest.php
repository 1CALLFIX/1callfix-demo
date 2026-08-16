<?php

namespace Tests\Feature\Modules;

use App\Livewire\Modules\Manage as ModulesManage;
use App\Models\ModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Phase 22.1 (Module Activation Foundation). The new admin screen's own
 * permission gate + the UI-level guarantee that an unimplemented module can
 * never be switched "on" through it, matching the same conventions every
 * other screen this codebase's RBAC audit (Phase 11/19) already verified.
 */
class ModulesScreenAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_denied_without_modules_manage_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(ModulesManage::class)->assertForbidden();
    }

    public function test_allowed_with_modules_manage_permission(): void
    {
        $actor = $this->makeUserWithPermission('modules.manage', 'global');

        Livewire::actingAs($actor)->test(ModulesManage::class)->assertOk();
    }

    public function test_super_admin_bypasses_the_gate(): void
    {
        $actor = $this->makeSuperAdmin();

        Livewire::actingAs($actor)->test(ModulesManage::class)->assertOk();
    }

    public function test_toggling_an_unimplemented_module_is_a_no_op(): void
    {
        $actor = $this->makeSuperAdmin();
        $franchise = $this->makeFranchise();

        Livewire::actingAs($actor)->test(ModulesManage::class)
            ->set('scopeLevel', 'franchise')
            ->set('scopeId', $franchise->id)
            ->call('toggle', 'food');

        $this->assertDatabaseMissing('module_activations', [
            'scope_type' => 'franchise',
            'scope_id' => $franchise->id,
            'module_id' => \App\Models\Module::where('code', 'food')->value('id'),
        ]);
    }

    public function test_toggling_the_implemented_service_module_writes_a_real_row(): void
    {
        $actor = $this->makeSuperAdmin();
        $franchise = $this->makeFranchise();

        // FranchiseObserver already left service=true here; toggling once
        // should flip it to false.
        Livewire::actingAs($actor)->test(ModulesManage::class)
            ->set('scopeLevel', 'franchise')
            ->set('scopeId', $franchise->id)
            ->call('toggle', 'service');

        $row = ModuleActivation::where('scope_type', 'franchise')->where('scope_id', $franchise->id)
            ->where('module_id', \App\Models\Module::where('code', 'service')->value('id'))
            ->first();

        $this->assertNotNull($row);
        $this->assertFalse($row->is_active);
    }

    public function test_toggle_action_itself_is_permission_gated(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        // A no-permission actor can't even mount the component (already
        // covered above), but this proves the mutating action re-checks
        // too, not just mount() -- same defense-in-depth convention as
        // Chat\Manage::selectBooking(). Calling the method directly
        // (bypassing Livewire's own mount() lifecycle) isolates that.
        $this->actingAs($actor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new ModulesManage())->toggle('service');
    }
}
