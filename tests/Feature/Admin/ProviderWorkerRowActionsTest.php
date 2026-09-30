<?php

namespace Tests\Feature\Admin;

use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Models\ActivityLog;
use App\Models\FieldWorker;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-ADMIN-ROWACTIONS-001 — provider and worker lists: Delete (archive), Restore,
 * Archived tab, and Super-Admin-only permanent delete.
 */
class ProviderWorkerRowActionsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_provider_delete_archives_shows_under_archived_and_restores(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(ProvidersIndex::class);

        $c->call('askArchive', $provider->id)->call('confirmArchive');
        $this->assertSoftDeleted('providers', ['id' => $provider->id]);
        $this->assertNotContains($provider->id, $c->viewData('providers')->pluck('id')->all());

        $c->set('statusFilter', 'archived');
        $this->assertContains($provider->id, $c->viewData('providers')->pluck('id')->all());

        $c->call('restoreRow', $provider->id);
        $this->assertNotSoftDeleted('providers', ['id' => $provider->id]);
        $this->assertSame(['archived', 'restored'], ActivityLog::where('subject_type', Provider::class)->where('subject_id', $provider->id)->orderBy('id')->pluck('description')->all());
    }

    public function test_provider_permanent_delete_is_super_admin_only(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $viewer = $this->makeUserWithPermission('providers.view', 'global');
        Livewire::actingAs($viewer)->test(ProvidersIndex::class)->call('askArchive', $provider->id)->assertForbidden();
        Livewire::actingAs($viewer)->test(ProvidersIndex::class)->call('askForceDelete', $provider->id)->assertForbidden();
        $this->assertNotSoftDeleted('providers', ['id' => $provider->id]);
    }

    public function test_worker_delete_and_restore(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeFieldWorkerIn($franchise, $zone);
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(WorkersIndex::class);

        $c->call('askArchive', $worker->id)->call('confirmArchive');
        $this->assertSoftDeleted('field_workers', ['id' => $worker->id]);

        $c->set('statusFilter', 'archived');
        $this->assertContains($worker->id, $c->viewData('workers')->pluck('id')->all());

        $c->call('restoreRow', $worker->id);
        $this->assertNotSoftDeleted('field_workers', ['id' => $worker->id]);
        $this->assertNotNull(FieldWorker::find($worker->id));
    }

    public function test_worker_delete_is_refused_without_the_permission(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeFieldWorkerIn($franchise, $zone);
        $viewer = $this->makeUserWithPermission('workers.view', 'global');

        Livewire::actingAs($viewer)->test(WorkersIndex::class)->call('askArchive', $worker->id)->assertForbidden();
        $this->assertNotSoftDeleted('field_workers', ['id' => $worker->id]);
    }
}
