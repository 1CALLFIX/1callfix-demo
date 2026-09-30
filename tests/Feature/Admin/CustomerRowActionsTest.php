<?php

namespace Tests\Feature\Admin;

use App\Livewire\Customers\Index;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-ADMIN-ROWACTIONS-001 — customer list: Edit, Delete (archive), Restore and
 * Super-Admin-only permanent delete.
 */
class CustomerRowActionsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_delete_archives_the_customer_restore_brings_it_back_and_both_are_audited(): void
    {
        $customer = $this->makeCustomer();
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(Index::class);

        $c->call('askArchive', $customer->id)->assertSet('confirmingArchiveId', $customer->id)->call('confirmArchive');

        $this->assertSoftDeleted('users', ['id' => $customer->id]);
        $this->assertNotContains($customer->id, $c->viewData('customers')->pluck('id')->all());

        $c->set('statusFilter', 'archived');
        $this->assertContains($customer->id, $c->viewData('customers')->pluck('id')->all());

        $c->call('restoreRow', $customer->id);
        $this->assertNotSoftDeleted('users', ['id' => $customer->id]);

        $this->assertSame(['archived', 'restored'], ActivityLog::where('subject_id', $customer->id)->orderBy('id')->pluck('description')->all());
    }

    public function test_view_only_admin_cannot_delete_or_permanently_delete(): void
    {
        $customer = $this->makeCustomer();
        $viewer = $this->makeUserWithPermission('customers.view', 'global');

        Livewire::actingAs($viewer)->test(Index::class)->call('askArchive', $customer->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(Index::class)->call('askForceDelete', $customer->id)->assertForbidden();
        $this->assertNotSoftDeleted('users', ['id' => $customer->id]);
    }

    public function test_a_crafted_id_cannot_delete_a_non_customer_account(): void
    {
        $admin = $this->makeSuperAdmin();
        $other = $this->makeUserWithNoPermissions(); // role=support, i.e. staff

        Livewire::actingAs($admin)->test(Index::class)->call('askArchive', $other->id)->assertForbidden();
        $this->assertNotSoftDeleted('users', ['id' => $other->id]);
    }

    public function test_edit_saves_changes_audits_them_and_rejects_a_duplicate_phone(): void
    {
        $a = $this->makeCustomer();
        $b = $this->makeCustomer();
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(Index::class);

        $c->call('editCustomer', $a->id)->assertSet('showEditModal', true)
            ->set('editName', 'Renamed Person')->set('editStatus', 'suspended')
            ->call('saveCustomer')->assertHasNoErrors()->assertSet('showEditModal', false);
        $this->assertSame(['Renamed Person', 'suspended'], [$a->fresh()->name, $a->fresh()->status]);
        $this->assertTrue(ActivityLog::where('subject_id', $a->id)->where('description', 'customer edited')->exists());

        $c->call('editCustomer', $a->id)->set('editPhone', $b->phone)->call('saveCustomer')->assertHasErrors('editPhone');
    }

    public function test_permanent_delete_is_super_admin_only_needs_the_archive_step_first_and_removes_the_row(): void
    {
        $customer = $this->makeCustomer();
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(Index::class);

        // Not archived yet -> refused with a message, nothing removed.
        $c->call('askForceDelete', $customer->id)->assertSet('confirmingForceDeleteId', null);
        $this->assertNotNull(User::find($customer->id));

        $c->call('askArchive', $customer->id)->call('confirmArchive');
        $c->call('askForceDelete', $customer->id)->assertSet('confirmingForceDeleteId', $customer->id)->call('confirmForceDelete');

        $this->assertNull(User::withTrashed()->find($customer->id));
    }
}
