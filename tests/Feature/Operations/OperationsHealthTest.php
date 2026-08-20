<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\Health;
use App\Models\ActivityLog;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Regression coverage for the Operations/Troubleshoot screen this session's
 * forensic audit found completely absent (no failed-job visibility, no
 * notification-failure visibility, no health indicators anywhere in the
 * admin panel) -- see the mission's own "PAYMENT / OPERATIONS / TROUBLESHOOT
 * SPECIAL INSTRUCTION" section. operations.view/operations.manage are new,
 * additive, super-admin-only-by-default permissions (2026_08_14_001000),
 * gated the same way every other screen this session closed the same gap
 * for (see ScreenViewAuthorizationTest's own docblock).
 */
class OperationsHealthTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function makeFailedJob(): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ServiceMatchingJob', 'data' => []]),
            'exception' => "RuntimeException: Simulated failure\n#0 {main}",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_view_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Health::class)->assertForbidden();
    }

    public function test_view_allowed_with_operations_view_permission(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $this->makeFailedJob();
        NotificationLog::create([
            'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1,
            'channel' => 'sms', 'notification_type' => 'App\\Notifications\\BookingOtpNotification',
            'event' => 'booking_start', 'status' => 'failed', 'sent_at' => now(),
        ]);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee('ServiceMatchingJob')
            ->assertSee('BookingOtpNotification');
    }

    public function test_super_admin_bypasses_scope_entirely(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Health::class)->assertOk();
    }

    public function test_retry_denied_without_operations_manage(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $uuid = $this->makeFailedJob();

        Livewire::actingAs($actor)->test(Health::class)
            ->call('retryJob', $uuid);

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_discard_denied_without_operations_manage(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $uuid = $this->makeFailedJob();

        Livewire::actingAs($actor)->test(Health::class)
            ->call('discardJob', $uuid);

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_discard_removes_the_job_with_operations_manage(): void
    {
        $actor = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($actor, 'operations.view');
        $uuid = $this->makeFailedJob();

        Livewire::actingAs($actor)->test(Health::class)
            ->call('discardJob', $uuid)
            ->assertOk();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    /**
     * Admin Command Center mission (Phase 1/19 audit finding) — ActivityLog
     * has real writers (Operations' own retryJob/discardJob/
     * reprocessWebhook, plus Modules\Manage::toggle() as of this session)
     * but no admin screen displayed it. Now visible on this same screen.
     */
    public function test_activity_log_entries_are_visible_to_a_scoped_operator(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        ActivityLog::create([
            'causer_id' => $actor->id, 'subject_type' => 'App\\Models\\ModuleActivation', 'subject_id' => 1,
            'description' => "Module 'service' deactivated for franchise #1", 'properties' => ['module_code' => 'service'],
        ]);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee("Module 'service' deactivated for franchise #1")
            ->assertSee('ModuleActivation #1');
    }

    public function test_retry_removes_the_job_from_failed_jobs_with_operations_manage(): void
    {
        $actor = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($actor, 'operations.view');
        $uuid = $this->makeFailedJob();

        Livewire::actingAs($actor)->test(Health::class)
            ->call('retryJob', $uuid)
            ->assertOk();

        // queue:retry itself deletes the failed_jobs row once it successfully
        // re-pushes the job onto its original connection/queue.
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }
}
