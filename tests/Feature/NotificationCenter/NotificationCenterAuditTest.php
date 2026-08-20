<?php

namespace Tests\Feature\NotificationCenter;

use App\Livewire\NotificationCenter\Manage;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Notification/Communication Center completeness audit (mission Phase 8).
 * Real gaps found by direct inspection before building anything:
 * notification_templates/notification.manage_templates existed with ZERO
 * admin CRUD; notification_logs/notification.view_logs existed with ZERO
 * browsing UI (Operations\Health only ever showed the last 20 failures,
 * unfiltered); no provider/adapter status visibility anywhere; no working
 * retry; and Laravel's own 'database' notification channel (the real
 * in_app channel) had zero read-side API despite being actively written to
 * by every existing ->notify() call that includes 'in_app'.
 */
class NotificationCenterAuditTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    // ============================== Templates CRUD ==============================

    public function test_template_create_denied_without_permission(): void
    {
        $actor = $this->makeUserWithPermission('notification.view', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('templateKey', 'welcome')->set('templateName', 'Welcome')
            ->set('templateTitle', 'Hi {{name}}')->set('templateBody', 'Welcome aboard!')
            ->call('saveTemplate');

        $this->assertSame(0, NotificationTemplate::count());
    }

    public function test_template_create_succeeds_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('notification.view', 'global');
        $this->grantPermission($actor, 'notification.manage_templates', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('templateKey', 'welcome')->set('templateName', 'Welcome')
            ->set('templateTitle', 'Hi {{name}}')->set('templateBody', 'Welcome aboard!')
            ->call('saveTemplate');

        $this->assertSame(1, NotificationTemplate::count());
        $this->assertSame('welcome', NotificationTemplate::first()->key);
    }

    public function test_template_key_must_be_unique(): void
    {
        NotificationTemplate::create(['key' => 'welcome', 'name' => 'W', 'title_template' => 't', 'body_template' => 'b']);
        $actor = $this->makeUserWithPermission('notification.view', 'global');
        $this->grantPermission($actor, 'notification.manage_templates', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('templateKey', 'welcome')->set('templateName', 'Dup')
            ->set('templateTitle', 't2')->set('templateBody', 'b2')
            ->call('saveTemplate')
            ->assertHasErrors(['templateKey']);

        $this->assertSame(1, NotificationTemplate::count());
    }

    public function test_template_update_preserves_same_key(): void
    {
        $template = NotificationTemplate::create(['key' => 'welcome', 'name' => 'W', 'title_template' => 't', 'body_template' => 'b']);
        $actor = $this->makeUserWithPermission('notification.view', 'global');
        $this->grantPermission($actor, 'notification.manage_templates', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->call('startEditingTemplate', $template->id)
            ->set('templateName', 'Updated Name')
            ->call('saveTemplate');

        $this->assertSame('Updated Name', $template->fresh()->name);
        $this->assertSame(1, NotificationTemplate::count());
    }

    public function test_template_delete_denied_without_permission(): void
    {
        $template = NotificationTemplate::create(['key' => 'welcome', 'name' => 'W', 'title_template' => 't', 'body_template' => 'b']);
        $actor = $this->makeUserWithPermission('notification.view', 'global');

        Livewire::actingAs($actor)->test(Manage::class)->call('deleteTemplate', $template->id);

        $this->assertSame(1, NotificationTemplate::count());
    }

    public function test_template_delete_succeeds_with_permission(): void
    {
        $template = NotificationTemplate::create(['key' => 'welcome', 'name' => 'W', 'title_template' => 't', 'body_template' => 'b']);
        $actor = $this->makeUserWithPermission('notification.view', 'global');
        $this->grantPermission($actor, 'notification.manage_templates', 'global');

        Livewire::actingAs($actor)->test(Manage::class)->call('deleteTemplate', $template->id);

        $this->assertSame(0, NotificationTemplate::count());
    }

    // ============================== Delivery logs ==============================

    public function test_logs_hidden_without_view_logs_permission(): void
    {
        $actor = $this->makeUserWithPermission('notification.view', 'global');

        $component = Livewire::actingAs($actor)->test(Manage::class)->set('section', 'logs');

        $this->assertFalse($component->viewData('canViewLogs'));
    }

    public function test_logs_visible_with_permission_and_filterable_by_status(): void
    {
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => 1, 'channel' => 'mail', 'notification_type' => 'Test', 'event' => 'test.sent', 'status' => 'sent', 'sent_at' => now()]);
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => 1, 'channel' => 'sms', 'notification_type' => 'Test', 'event' => 'test.failed', 'status' => 'failed', 'error' => 'Timeout', 'sent_at' => now()]);

        $actor = $this->makeUserWithPermission('notification.view', 'global');
        $this->grantPermission($actor, 'notification.view_logs', 'global');

        $component = Livewire::actingAs($actor)->test(Manage::class)->set('section', 'logs')->set('logStatusFilter', 'failed');

        $this->assertTrue($component->viewData('canViewLogs'));
        $this->assertCount(1, $component->viewData('logs'));
    }

    /**
     * Admin Command Center completion session, CMS/Communication Center
     * phase (2026-08-20) — notification.view_logs never applied row-level
     * scope at all before this fix (same "assignable to other roles later"
     * gap already found twice this session for operations.view/banners.manage).
     * A franchise-scoped grant must see only logs for its own franchise's
     * users, resolved via the polymorphic notifiable -> User -> franchise_id
     * path.
     */
    public function test_logs_are_scoped_to_the_actors_own_franchise(): void
    {
        $myFranchise = $this->makeFranchise();
        $otherFranchise = $this->makeFranchise();
        $myUser = User::create(['uuid' => Str::uuid(), 'name' => 'Mine', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'customer', 'status' => 'active', 'franchise_id' => $myFranchise->id]);
        $otherUser = User::create(['uuid' => Str::uuid(), 'name' => 'Other', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'customer', 'status' => 'active', 'franchise_id' => $otherFranchise->id]);
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => $myUser->id, 'channel' => 'mail', 'notification_type' => 'Test', 'event' => 'mine.sent', 'status' => 'sent', 'sent_at' => now()]);
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => $otherUser->id, 'channel' => 'mail', 'notification_type' => 'Test', 'event' => 'other.sent', 'status' => 'sent', 'sent_at' => now()]);

        $actor = $this->makeUserWithPermission('notification.view', 'global');
        $this->grantPermission($actor, 'notification.view_logs', 'franchise', $myFranchise->id);

        $logs = Livewire::actingAs($actor)->test(Manage::class)->set('section', 'logs')->viewData('logs');

        $this->assertTrue($logs->contains('event', 'mine.sent'));
        $this->assertFalse($logs->contains('event', 'other.sent'));
    }

    // ============================== Provider status ==============================

    public function test_provider_status_shows_log_fallback_by_default(): void
    {
        $actor = $this->makeUserWithPermission('notification.view', 'global');

        $component = Livewire::actingAs($actor)->test(Manage::class)->set('section', 'status');

        $status = $component->viewData('providerStatus');
        $this->assertTrue($status['sms_is_log_fallback']);
        $this->assertTrue($status['push_is_log_fallback']);
        $this->assertSame('LogSmsAdapter', $status['sms_adapter']);
    }

    public function test_provider_status_never_exposes_credentials(): void
    {
        $actor = $this->makeUserWithPermission('notification.view', 'global');

        $component = Livewire::actingAs($actor)->test(Manage::class)->set('section', 'status');

        $status = $component->viewData('providerStatus');
        $this->assertArrayNotHasKey('key', $status);
        $this->assertArrayNotHasKey('secret', $status);
        $this->assertArrayNotHasKey('token', $status);
    }

    public function test_delivery_stats_reflect_real_log_counts(): void
    {
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => 1, 'channel' => 'mail', 'notification_type' => 'Test', 'event' => 'e', 'status' => 'sent', 'sent_at' => now()]);
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => 1, 'channel' => 'mail', 'notification_type' => 'Test', 'event' => 'e', 'status' => 'sent', 'sent_at' => now()]);
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => 1, 'channel' => 'mail', 'notification_type' => 'Test', 'event' => 'e', 'status' => 'failed', 'sent_at' => now()]);

        $actor = $this->makeUserWithPermission('notification.view', 'global');
        $component = Livewire::actingAs($actor)->test(Manage::class)->set('section', 'status');

        $stats = $component->viewData('deliveryStats');
        $this->assertSame(2, $stats['mail']['sent']);
        $this->assertSame(1, $stats['mail']['failed']);
    }

    // ============================== Campaign send queuing (mission Phase 18) ==============================

    /**
     * Phase 18 (performance/scale audit) finding: CampaignNotification had
     * `use Queueable` but never `implements ShouldQueue`, so
     * CampaignService::send()'s `foreach ($recipients as $r) { $r->notify(...) }`
     * ran every recipient's send fully synchronously, in-process, inside
     * whatever request/command triggered it — a real timeout risk for any
     * campaign with more than a handful of recipients. Proves the fix
     * actually queues, not just that send() still returns a sent campaign
     * (that alone would pass unchanged with a Queue::fake() bypass here).
     */
    public function test_campaign_send_queues_recipient_notifications_instead_of_sending_inline(): void
    {
        Queue::fake();

        $campaign = $this->makeCampaign(['status' => 'draft']);
        $this->makeUser();
        $this->makeUser();

        app(CampaignService::class)->send($campaign->fresh());

        Queue::assertPushed(SendQueuedNotifications::class);
    }

    // ============================== Audience chunking (Phase 21 item TECH-7) ==============================

    /**
     * `CampaignService::send()` (mission Phase 8/Phase 18) and
     * `resendToFailedRecipients()` both used to hydrate the entire resolved
     * audience into memory in one `->get()` before looping -- real but
     * low-severity-today, logged as risk register item 28 rather than
     * stacking a second behavioral change onto Phase 18's just-fixed
     * ShouldQueue methods. Phase 21 item TECH-7 rewrote both to
     * `chunkById(200, ...)`, matching `KycReminderService::dispatchDue()`'s
     * own established pattern. This proves the fix's actual mechanism --
     * `users` gets queried in ceil(N/200) chunked SELECTs, not one -- for an
     * audience that crosses the 200-row chunk boundary, not just that the
     * final count is still correct (which would pass unchanged against the
     * old single ->get() too).
     */
    public function test_campaign_send_chunks_the_audience_query_across_the_chunk_boundary(): void
    {
        Queue::fake();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $campaign = $this->makeCampaign(['status' => 'draft']);
        for ($i = 0; $i < 205; $i++) {
            $this->makeUser();
        }

        \Illuminate\Support\Facades\DB::flushQueryLog();
        $sent = app(CampaignService::class)->send($campaign->fresh());

        $usersSelects = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'select * from "users"') || str_contains($q['query'], 'select * from `users`'))
            ->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // 205 recipients over a 200-row chunk -> exactly 2 SELECTs against
        // `users` (200 + 5), never 1 (the old unchunked behavior) and never
        // one-per-row (a naive N+1 rewrite would be worse, not better).
        $this->assertSame(2, $usersSelects);
        $this->assertSame(205, $sent->recipient_count);
        Queue::assertPushed(SendQueuedNotifications::class, 205);
    }

    public function test_campaign_send_recipient_count_is_correct_exactly_at_the_chunk_boundary(): void
    {
        Queue::fake();

        $campaign = $this->makeCampaign(['status' => 'draft']);
        for ($i = 0; $i < 200; $i++) {
            $this->makeUser();
        }

        $sent = app(CampaignService::class)->send($campaign->fresh());

        $this->assertSame(200, $sent->recipient_count);
        Queue::assertPushed(SendQueuedNotifications::class, 200);
    }

    public function test_resend_to_failed_recipients_chunks_across_the_chunk_boundary(): void
    {
        Queue::fake();

        $campaign = $this->makeCampaign();
        $recipientIds = [];
        for ($i = 0; $i < 205; $i++) {
            $user = $this->makeUser();
            $recipientIds[] = $user->id;
            NotificationLog::create([
                'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'campaign_id' => $campaign->id,
                'channel' => 'mail', 'notification_type' => 'CampaignNotification', 'event' => 'campaign.sent',
                'status' => 'failed', 'sent_at' => now(),
            ]);
        }

        $count = app(CampaignService::class)->resendToFailedRecipients($campaign);

        $this->assertSame(205, $count);
        Queue::assertPushed(SendQueuedNotifications::class, 205);
    }

    // ============================== Resend to failed recipients ==============================

    private function makeCampaign(array $overrides = []): NotificationCampaign
    {
        return NotificationCampaign::create(array_merge([
            'category' => 'business', 'type' => 'promotion', 'title' => 'T', 'message' => 'M',
            'recipient_type' => 'everyone', 'scope_type' => 'global', 'channels' => 'mail',
            'status' => 'sent', 'sent_at' => now(), 'recipient_count' => 1,
        ], $overrides));
    }

    private function makeUser(): User
    {
        return User::create(['uuid' => (string) Str::uuid(), 'name' => 'U', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
    }

    public function test_resend_only_targets_recipients_whose_latest_attempt_failed(): void
    {
        $campaign = $this->makeCampaign();
        $stillFailing = $this->makeUser();
        $recovered = $this->makeUser();

        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => $stillFailing->id, 'campaign_id' => $campaign->id, 'channel' => 'mail', 'notification_type' => 'CampaignNotification', 'event' => 'campaign.sent', 'status' => 'failed', 'sent_at' => now()]);
        // recovered: failed once, then succeeded (higher id = more recent) -- must NOT be resent.
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => $recovered->id, 'campaign_id' => $campaign->id, 'channel' => 'mail', 'notification_type' => 'CampaignNotification', 'event' => 'campaign.sent', 'status' => 'failed', 'sent_at' => now()]);
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => $recovered->id, 'campaign_id' => $campaign->id, 'channel' => 'mail', 'notification_type' => 'CampaignNotification', 'event' => 'campaign.sent', 'status' => 'sent', 'sent_at' => now()]);

        $count = app(CampaignService::class)->resendToFailedRecipients($campaign);

        $this->assertSame(1, $count);
    }

    public function test_resend_requires_sent_status(): void
    {
        $campaign = $this->makeCampaign(['status' => 'draft']);

        $this->expectException(\RuntimeException::class);
        app(CampaignService::class)->resendToFailedRecipients($campaign);
    }

    public function test_resend_returns_zero_when_nothing_failed(): void
    {
        $campaign = $this->makeCampaign();
        $user = $this->makeUser();
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => $user->id, 'campaign_id' => $campaign->id, 'channel' => 'mail', 'notification_type' => 'CampaignNotification', 'event' => 'campaign.sent', 'status' => 'sent', 'sent_at' => now()]);

        $count = app(CampaignService::class)->resendToFailedRecipients($campaign);

        $this->assertSame(0, $count);
    }

    public function test_livewire_resend_requires_manage_campaigns_permission(): void
    {
        $campaign = $this->makeCampaign();
        $user = $this->makeUser();
        NotificationLog::create(['notifiable_type' => User::class, 'notifiable_id' => $user->id, 'campaign_id' => $campaign->id, 'channel' => 'mail', 'notification_type' => 'CampaignNotification', 'event' => 'e', 'status' => 'failed', 'sent_at' => now()]);

        $actor = $this->makeUserWithPermission('notification.view', 'global');

        $component = Livewire::actingAs($actor)->test(Manage::class)->call('resendFailed', $campaign->id);

        $this->assertSame('error', $component->get('flashType'));
    }

    // ============================== In-app notifications API ==============================

    public function test_unauthenticated_cannot_list_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_user_can_list_own_notifications(): void
    {
        $user = $this->makeUser();
        DatabaseNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\CampaignNotification',
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => ['title' => 'Hello'],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_cannot_see_another_users_notifications(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'X', 'notifiable_type' => User::class, 'notifiable_id' => $other->id, 'data' => []]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $this->assertCount(0, $response->json('data'));
    }

    public function test_unread_count_reflects_real_unread_notifications(): void
    {
        $user = $this->makeUser();
        DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'X', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => []]);
        DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'X', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => [], 'read_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications/unread-count');

        $response->assertOk()->assertJson(['count' => 1]);
    }

    public function test_mark_read_marks_own_notification(): void
    {
        $user = $this->makeUser();
        $id = (string) Str::uuid();
        DatabaseNotification::create(['id' => $id, 'type' => 'X', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => []]);

        $this->actingAs($user, 'sanctum')->postJson("/api/notifications/{$id}/read")->assertOk();

        $this->assertNotNull(DatabaseNotification::find($id)->read_at);
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $id = (string) Str::uuid();
        DatabaseNotification::create(['id' => $id, 'type' => 'X', 'notifiable_type' => User::class, 'notifiable_id' => $other->id, 'data' => []]);

        $this->actingAs($user, 'sanctum')->postJson("/api/notifications/{$id}/read")->assertNotFound();

        $this->assertNull(DatabaseNotification::find($id)->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = $this->makeUser();
        DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'X', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => []]);
        DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'X', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => []]);

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(0, $user->unreadNotifications()->count());
    }
}
