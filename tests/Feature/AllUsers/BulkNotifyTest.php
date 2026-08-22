<?php

namespace Tests\Feature\AllUsers;

use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Contracts\WhatsAppAdapter;
use App\Livewire\AllUsers\Index as AllUsersIndex;
use App\Models\ActivityLog;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Unified "All Users" Directory session, Part 2 (Bulk Notify). "This
 * touches real people's contact information in bulk — treat the scoping
 * tests with the same seriousness as the RBAC audit sessions earlier in
 * this project" — mission spec, verbatim.
 */
class BulkNotifyTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeSender(string $scopeType, ?int $scopeId = null): User
    {
        $actor = $this->makeUserWithPermission('notification.send', $scopeType, $scopeId);
        $this->grantPermission($actor, 'notification.direct', $scopeType, $scopeId);
        $this->grantPermission($actor, 'users.directory.view', $scopeType, $scopeId);

        return $actor;
    }

    // ============================== Scope on SEND, not just view ==============================

    /**
     * THE regression test the mission spec asks for by name: "a
     * franchise-scoped admin's bulk-send request containing an
     * out-of-scope user ID is rejected server-side, not just hidden in
     * the UI." Sets selectedIds directly via the Livewire test harness
     * (bypassing whatever the checkbox UI would or wouldn't have allowed)
     * — exactly simulating a tampered/bypassed client, which is precisely
     * the threat model "checked server-side... not just filtered
     * client-side" is written against.
     */
    public function test_send_request_with_an_out_of_scope_user_id_is_rejected_and_nothing_is_sent(): void
    {
        Mail::fake();
        $own = $this->makeBookingScenario();
        $other = $this->makeBookingScenario();
        $actor = $this->makeSender('franchise', $own['franchise']->id);

        $component = Livewire::actingAs($actor)->test(AllUsersIndex::class)
            ->set('selectedIds', [$own['customer']->id, $other['customer']->id])
            ->call('openNotifyModal')
            ->set('notifySubject', 'Hello')
            ->set('notifyBody', 'A message')
            ->call('reviewNotify');

        $this->assertSame('error', $component->get('notifyFlashType'));
        $this->assertStringContainsString('outside your permitted scope', $component->get('notifyFlashMessage'));
        $this->assertSame('compose', $component->get('notifyStep'), 'Must never advance to the confirm step on a scope violation.');
        $this->assertSame(0, NotificationCampaign::count());
        Mail::assertNothingSent();
    }

    /** Same guarantee, re-checked at the FINAL send call too — never trusts reviewNotify()'s earlier pass alone. Forces notifyStep to 'confirm' directly (simulating a client that raced past the compose step) to prove confirmSend() itself independently refuses. */
    public function test_confirm_send_independently_re_checks_scope_even_if_notify_step_was_forced_to_confirm(): void
    {
        Mail::fake();
        $own = $this->makeBookingScenario();
        $other = $this->makeBookingScenario();
        $actor = $this->makeSender('franchise', $own['franchise']->id);

        $component = Livewire::actingAs($actor)->test(AllUsersIndex::class)
            ->set('selectedIds', [$own['customer']->id, $other['customer']->id])
            ->set('notifySubject', 'Hello')
            ->set('notifyBody', 'A message')
            ->set('notifyChannels', ['mail' => true, 'sms' => false, 'push' => false, 'whatsapp' => false])
            ->set('notifyStep', 'confirm')
            ->call('confirmSend');

        $this->assertSame('error', $component->get('notifyFlashType'));
        $this->assertSame(0, NotificationCampaign::count());
        Mail::assertNothingSent();
    }

    public function test_franchise_scoped_admin_can_send_to_their_own_franchises_customers(): void
    {
        Mail::fake();
        $scenario = $this->makeBookingScenario();
        $actor = $this->makeSender('franchise', $scenario['franchise']->id);

        $component = Livewire::actingAs($actor)->test(AllUsersIndex::class)
            ->set('selectedIds', [$scenario['customer']->id])
            ->call('openNotifyModal')
            ->set('notifySubject', 'Hello')
            ->set('notifyBody', 'A message')
            ->call('reviewNotify');

        $this->assertSame('confirm', $component->get('notifyStep'));
        $this->assertSame(1, $component->get('notifyConfirmedCount'));

        $component->call('confirmSend');

        $this->assertSame('success', $component->get('notifyFlashType'));
        $this->assertSame(1, NotificationCampaign::count());
    }

    // ============================== Confirmation required ==============================

    /** "a confirmation step showing 'this will message 47 people' before actually sending" — proves confirmSend() cannot be reached in one step. */
    public function test_confirm_send_is_refused_without_first_reviewing(): void
    {
        Mail::fake();
        $scenario = $this->makeBookingScenario();
        $actor = $this->makeSender('global');

        $component = Livewire::actingAs($actor)->test(AllUsersIndex::class)
            ->set('selectedIds', [$scenario['customer']->id])
            ->set('notifySubject', 'Hello')
            ->set('notifyBody', 'A message')
            ->set('notifyChannels', ['mail' => true, 'sms' => false, 'push' => false, 'whatsapp' => false])
            // notifyStep left at its default 'compose' -- reviewNotify() was never called.
            ->call('confirmSend');

        $this->assertSame('error', $component->get('notifyFlashType'));
        $this->assertSame(0, NotificationCampaign::count());
        Mail::assertNothingSent();
    }

    // ============================== Real adapters invoked, not reimplemented ==============================

    /**
     * Binds a capturing spy over EACH real adapter contract (SmsAdapter,
     * PushAdapter, WhatsAppAdapter) plus Mail::fake() for the mail leg —
     * proves the send genuinely flows through CampaignNotification ->
     * SmsChannel/PushChannel/WhatsAppChannel -> the adapter, the exact
     * existing pipeline, not a parallel implementation built for this
     * screen.
     */
    public function test_all_four_real_adapters_are_actually_invoked_when_selected(): void
    {
        Mail::fake();
        $sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $sms);

        $push = new class implements PushAdapter {
            public array $sent = [];

            public function send(string $token, string $title, string $body): bool
            {
                $this->sent[] = compact('token', 'title', 'body');

                return true;
            }
        };
        $this->app->instance(PushAdapter::class, $push);

        $whatsapp = new class implements WhatsAppAdapter {
            public array $sent = [];

            public function send(string $to, string $message): bool
            {
                $this->sent[] = compact('to', 'message');

                return true;
            }
        };
        $this->app->instance(WhatsAppAdapter::class, $whatsapp);

        $target = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Target', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'email' => 'target@example.test', 'fcm_token' => 'a-real-looking-token', 'role' => 'customer', 'status' => 'active',
        ]);
        $actor = $this->makeSender('global');

        Livewire::actingAs($actor)->test(AllUsersIndex::class)
            ->set('selectedIds', [$target->id])
            ->call('openNotifyModal')
            ->set('notifySubject', 'Important Update')
            ->set('notifyBody', 'Please read this.')
            ->set('notifyChannels', ['mail' => true, 'sms' => true, 'push' => true, 'whatsapp' => true])
            ->call('reviewNotify')
            ->call('confirmSend');

        // Mail's own "adapter" is Laravel's Notification mail channel itself
        // (no App\Contracts adapter to spy on, unlike sms/push/whatsapp) —
        // proven via the real NotificationLog row the SAME NotificationSent
        // event listener every other channel's entry below also produces,
        // not via Mail::fake()'s Mailable-specific assertions (the
        // Notification mail channel sends a raw view/closure message, not
        // a Mailable instance, so assertSentCount() doesn't apply here).
        $this->assertDatabaseHas('notification_logs', ['channel' => 'mail', 'status' => 'sent']);
        $this->assertNotNull($sms->lastMessageTo($target->phone), 'SmsChannel/SmsAdapter must actually be invoked.');
        $this->assertStringContainsString('Important Update', $sms->lastMessageTo($target->phone));
        $this->assertCount(1, $push->sent, 'PushChannel/PushAdapter must actually be invoked.');
        $this->assertSame('a-real-looking-token', $push->sent[0]['token']);
        $this->assertCount(1, $whatsapp->sent, 'WhatsAppChannel/WhatsAppAdapter must actually be invoked.');
        $this->assertSame($target->phone, $whatsapp->sent[0]['to']);
        $this->assertStringContainsString('Important Update', $whatsapp->sent[0]['message']);
    }

    // ============================== Audit log ==============================

    public function test_audit_log_entry_is_created_for_a_successful_send(): void
    {
        Mail::fake();
        $scenario = $this->makeBookingScenario();
        $actor = $this->makeSender('global');

        Livewire::actingAs($actor)->test(AllUsersIndex::class)
            ->set('selectedIds', [$scenario['customer']->id])
            ->call('openNotifyModal')
            ->set('notifySubject', 'Audit Me')
            ->set('notifyBody', 'Body text')
            ->set('notifyChannels', ['mail' => true, 'sms' => false, 'push' => false, 'whatsapp' => false])
            ->call('reviewNotify')
            ->call('confirmSend');

        $campaign = NotificationCampaign::sole();
        $log = ActivityLog::where('subject_type', NotificationCampaign::class)->where('subject_id', $campaign->id)->first();

        $this->assertNotNull($log, 'A bulk-notify send must produce a real activity_log row.');
        $this->assertSame($actor->id, $log->causer_id);
        $this->assertStringContainsString('1', $log->description); // recipient count
        $this->assertStringContainsString('Audit Me', $log->description);
        $this->assertSame([$scenario['customer']->id], $log->properties['recipient_ids']);
        $this->assertSame(['mail'], $log->properties['channels']);
    }

    public function test_no_audit_log_entry_when_the_send_is_blocked_by_scope(): void
    {
        Mail::fake();
        $own = $this->makeBookingScenario();
        $other = $this->makeBookingScenario();
        $actor = $this->makeSender('franchise', $own['franchise']->id);

        Livewire::actingAs($actor)->test(AllUsersIndex::class)
            ->set('selectedIds', [$own['customer']->id, $other['customer']->id])
            ->set('notifySubject', 'Hello')->set('notifyBody', 'Body')
            ->set('notifyChannels', ['mail' => true, 'sms' => false, 'push' => false, 'whatsapp' => false])
            ->call('reviewNotify');

        $this->assertSame(0, ActivityLog::count());
    }
}
