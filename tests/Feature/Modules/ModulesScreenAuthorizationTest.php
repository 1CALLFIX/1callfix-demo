<?php

namespace Tests\Feature\Modules;

use App\Livewire\Modules\Manage as ModulesManage;
use App\Models\ActivityLog;
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

    /**
     * Admin Command Center mission (Phase 1 audit finding) — toggle() wrote
     * only to module_activations, which retains just the MOST RECENT actor
     * (created_by_user_id is overwritten on every call); no history of
     * prior activation changes survived. Wired into ActivityLog, this
     * codebase's own established pattern for auditing consequential admin
     * mutations (see OperationsHealthTest's equivalent coverage).
     */
    public function test_toggle_writes_an_activity_log_entry(): void
    {
        $actor = $this->makeSuperAdmin();
        $franchise = $this->makeFranchise();

        Livewire::actingAs($actor)->test(ModulesManage::class)
            ->set('scopeLevel', 'franchise')
            ->set('scopeId', $franchise->id)
            ->call('toggle', 'service');

        $log = ActivityLog::where('subject_type', ModuleActivation::class)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->causer_id);
        $this->assertSame('service', $log->properties['module_code']);
        $this->assertSame('franchise', $log->properties['scope_type']);
        $this->assertSame($franchise->id, $log->properties['scope_id']);
        $this->assertTrue($log->properties['was_active']);
        $this->assertFalse($log->properties['is_active']);
    }

    public function test_toggling_an_unimplemented_module_writes_no_activity_log(): void
    {
        $actor = $this->makeSuperAdmin();
        $franchise = $this->makeFranchise();

        Livewire::actingAs($actor)->test(ModulesManage::class)
            ->set('scopeLevel', 'franchise')
            ->set('scopeId', $franchise->id)
            ->call('toggle', 'food');

        $this->assertSame(0, ActivityLog::where('subject_type', ModuleActivation::class)->count());
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
