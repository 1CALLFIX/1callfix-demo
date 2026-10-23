<?php

namespace Tests\Feature\Backup;

use App\Jobs\RunBackupNowJob;
use App\Jobs\RunDataRestoreJob;
use App\Livewire\Geography\Manage as GeographyManage;
use App\Livewire\Operations\BackupRestore;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\DataRestore;
use App\Models\Zone;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupShell;
use App\Services\Backup\RestoreSnapshot;
use App\Services\Slug\SlugManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** REF 1CF-BACKUP-RESTORE-001 — System > Backup & Restore screen, its jobs, Deleted items, Layer 1 archiving. */
class BackupRestoreScreenTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;
    use SnapshotTestHelpers;

    private function backup(): array
    {
        $at = CarbonImmutable::now()->subMinutes(20)->startOfMinute();

        return ['id' => 'hourly/1cal_api_hourly_'.$at->format('Y-m-d_H-i-s').'.sql.gz', 'folder' => 'hourly',
            'file' => '1cal_api_hourly_'.$at->format('Y-m-d_H-i-s').'.sql.gz', 'at' => $at, 'label' => $at->format('d/m/Y H:i').' (hourly)'];
    }

    private function fakeBackupInfrastructure(): void
    {
        $backup = $this->backup();
        $catalog = Mockery::mock(BackupCatalog::class);
        $catalog->shouldReceive('find')->andReturn($backup);
        $catalog->shouldReceive('all')->andReturn(['backups' => [$backup], 'remote_error' => null]);
        $catalog->shouldReceive('status')->andReturn(['last_at' => $backup['at'], 'healthy' => true, 'copies' => 1, 'remote_error' => null]);
        $catalog->shouldReceive('forgetCache');
        $catalog->shouldReceive('refresh');
        $catalog->shouldReceive('isReady')->andReturn(true);
        $this->app->instance(BackupCatalog::class, $catalog);

        $snapshot = Mockery::mock(RestoreSnapshot::class);
        $snapshot->shouldReceive('ensureLoaded');
        $this->app->instance(RestoreSnapshot::class, $snapshot);

        $shell = Mockery::mock(BackupShell::class);
        $shell->shouldReceive('dumpTables')->andReturn(1234);
        $shell->shouldReceive('runBackupScript')->andReturn('Hourly offsite backup OK');
        $this->app->instance(BackupShell::class, $shell);
    }

    public function test_screen_needs_the_backups_restore_permission(): void
    {
        $this->fakeBackupInfrastructure();

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(BackupRestore::class)->assertForbidden();
        Livewire::actingAs($this->makeSuperAdmin())->test(BackupRestore::class)
            ->assertSee('Data backup')->assertSee('Healthy')->assertSee('Compare with now');
    }

    public function test_compare_then_restore_missing_end_to_end(): void
    {
        $this->fakeBackupInfrastructure();
        $this->registerSnapshotConnection();
        [, , $franchise] = $this->makeFranchiseTree();
        $zone = Zone::create(['franchise_id' => $franchise->id, 'name' => 'Lost zone', 'boundary_polygon' => [['lat' => 1, 'lng' => 1]], 'is_active' => true, 'default_dispatch_radius_km' => 8]);
        $this->takeSnapshot();
        $zone->forceDelete();
        $admin = $this->makeSuperAdmin();

        $c = Livewire::actingAs($admin)->test(BackupRestore::class)
            ->set('scopeType', 'franchise')->set('scopeId', (string) $franchise->id)->set('backupId', $this->backup()['id'])
            ->call('compare');

        $restore = DataRestore::firstOrFail();
        $this->assertSame(DataRestore::READY, $restore->status, (string) $restore->error);
        $this->assertSame(1, $restore->summary['totals']['missing']);

        $c->call('restoreMissing')->assertHasErrors(['reason', 'confirmText']);
        $c->set('reason', 'Zone deleted by mistake on 23 Oct')->set('confirmText', 'restore')->call('restoreMissing')->assertHasErrors(['confirmText']);
        $c->set('confirmText', 'RESTORE')->call('restoreMissing')->assertHasNoErrors();

        $restore->refresh();
        $this->assertSame(DataRestore::DONE, $restore->status, (string) $restore->error);
        $this->assertSame(['zones' => 1], $restore->result['inserted']);
        $this->assertNotNull($restore->safety_copy_path);
        $this->assertSame('Lost zone', Zone::find($zone->id)?->name);
        $this->assertTrue(ActivityLog::where('description', 'restored from backup')->where('causer_id', $admin->id)->exists());
        $c->assertSee('Restored.');
    }

    public function test_a_second_compare_is_refused_while_one_is_running(): void
    {
        $this->fakeBackupInfrastructure();
        Queue::fake();
        [, , $franchise] = $this->makeFranchiseTree();
        $admin = $this->makeSuperAdmin();
        DataRestore::create(['scope_type' => 'franchise', 'scope_id' => $franchise->id, 'backup_id' => 'x', 'status' => DataRestore::PREPARING, 'requested_by' => $admin->id]);

        Livewire::actingAs($admin)->test(BackupRestore::class)->call('startOver')
            ->set('scopeId', (string) $franchise->id)->set('backupId', $this->backup()['id'])->call('compare')
            ->assertSet('flashType', 'error');
        Queue::assertNotPushed(RunDataRestoreJob::class);
    }

    public function test_back_up_now_queues_the_script(): void
    {
        $this->fakeBackupInfrastructure();
        Queue::fake();

        Livewire::actingAs($this->makeSuperAdmin())->test(BackupRestore::class)->call('backUpNow')->assertSet('flashType', 'success');
        Queue::assertPushed(RunBackupNowJob::class);
    }

    public function test_deleting_a_city_archives_it_logs_it_and_deleted_items_restores_it(): void
    {
        $this->fakeBackupInfrastructure();
        $country = $this->makeCountry();
        $city = City::create(['country_id' => $country->id, 'name' => 'Kurnool', 'is_active' => true]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(GeographyManage::class)->call('deleteCity', $city->id)->assertSee('Deleted items');

        $this->assertSoftDeleted('cities', ['id' => $city->id]);
        $this->assertTrue(ActivityLog::where('subject_type', City::class)->where('subject_id', $city->id)->where('description', 'archived')->exists());
        $this->assertTrue(SlugManager::isTaken(new City(['country_id' => $country->id]), $city->slug), 'an archived city keeps its slug');

        Livewire::actingAs($admin)->test(BackupRestore::class)->set('tab', 'deleted')
            ->assertSee('Kurnool')->assertSee($admin->name)
            ->call('restoreDeleted', 'city', $city->id)->assertSet('flashType', 'success');

        $this->assertNotSoftDeleted('cities', ['id' => $city->id]);
    }

    public function test_restore_database_must_not_be_the_live_one(): void
    {
        config(['database.connections.restore' => null, 'backup.restore_database' => config('database.connections.'.config('database.default').'.database')]);

        $this->expectException(RuntimeException::class);
        RestoreSnapshot::connection();
    }

    public function test_catalog_lists_offsite_backups_newest_first_and_reports_health(): void
    {
        $recent = CarbonImmutable::now()->subMinutes(10);
        $shell = Mockery::mock(BackupShell::class);
        $shell->shouldReceive('remoteTree')->once()->with('db')->andReturn([
            'hourly' => ['1cal_api_hourly_'.$recent->format('Y-m-d_H-i-s').'.sql.gz', 'not-a-dump.txt'],
            'daily' => ['1cal_api_2026-10-01_02-00-01.sql.gz'],
        ]);
        config(['backup.remote' => 'gdrive-crypt:superapp', 'backup.local_dir' => storage_path('framework/testing/no-backups-here')]);

        $catalog = new BackupCatalog($shell);
        $this->assertFalse($catalog->isReady());
        $all = $catalog->all();

        $this->assertCount(2, $all['backups']);
        $this->assertSame('hourly', $all['backups'][0]['folder']);
        $this->assertStringEndsWith('(daily)', $all['backups'][1]['label']);
        $this->assertTrue($catalog->status()['healthy']);

        // One rclone call (once() above) serves every later page load from the cache.
        $this->assertTrue($catalog->isReady());
        $this->assertCount(2, (new BackupCatalog($shell))->all()['backups']);
    }

    public function test_an_unreachable_drive_is_remembered_briefly_so_pages_do_not_wait_on_it(): void
    {
        $shell = Mockery::mock(BackupShell::class);
        $shell->shouldReceive('remoteTree')->once()->andThrow(new RuntimeException('Could not list the off-site backups: timeout'));
        config(['backup.remote' => 'gdrive-crypt:superapp', 'backup.local_dir' => storage_path('framework/testing/no-backups-here')]);

        $this->assertStringContainsString('timeout', (new BackupCatalog($shell))->all()['remote_error']);
        $this->assertStringContainsString('timeout', (new BackupCatalog($shell))->status()['remote_error']);
    }

    public function test_screen_opens_without_waiting_for_the_backup_list_then_loads_it(): void
    {
        $shell = Mockery::mock(BackupShell::class);
        $shell->shouldReceive('remoteTree')->once()->andReturn(['hourly' => ['1cal_api_hourly_'.now()->subMinutes(5)->format('Y-m-d_H-i-s').'.sql.gz']]);
        $this->app->instance(BackupShell::class, $shell);
        config(['backup.remote' => 'gdrive-crypt:superapp', 'backup.local_dir' => storage_path('framework/testing/no-backups-here')]);

        Livewire::actingAs($this->makeSuperAdmin())->test(BackupRestore::class)
            ->assertSee('Checking the off-site backups')->assertDontSee('Healthy')
            ->call('loadCatalog')
            ->assertSee('Healthy')->assertDontSee('Checking the off-site backups');
    }

    public function test_refresh_command_relists_the_backups(): void
    {
        $shell = Mockery::mock(BackupShell::class);
        $shell->shouldReceive('remoteTree')->twice()->andReturn(['daily' => ['1cal_api_2026-10-01_02-00-01.sql.gz']]);
        $this->app->instance(BackupShell::class, $shell);
        config(['backup.remote' => 'gdrive-crypt:superapp', 'backup.local_dir' => storage_path('framework/testing/no-backups-here')]);

        $this->artisan('backups:refresh-catalog')->expectsOutput('1 backups listed.')->assertSuccessful();
        $this->artisan('backups:refresh-catalog')->assertSuccessful();
    }
}
