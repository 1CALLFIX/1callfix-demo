<?php

namespace Tests\Feature\Chat;

use App\Livewire\Chat\Manage as ChatManage;
use App\Models\ActivityLog;
use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 21 item TECH-4, Option A only (read-only admin chat viewer).
 * Closes risk register item 15 -- Universal Chat (mission Phase 6) had
 * zero admin-facing surface at any layer. This suite proves the new
 * chat.view-gated, row-scoped, audit-logged read path -- and, just as
 * importantly, that it adds NOTHING beyond viewing: no chat.moderate
 * permission exists, no send/edit/hide/delete capability was built, and
 * the existing customer/partner/worker chat authorization (ChatApiTest,
 * unchanged, still green) was not weakened anywhere.
 */
class AdminChatViewerTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeMessage($booking, int $senderId, int $receiverId, array $overrides = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'booking_id' => $booking->id, 'sender_id' => $senderId, 'receiver_id' => $receiverId,
            'message' => 'Test message '.Str::random(6),
        ], $overrides));
    }

    /** Two entirely independent franchise/zone trees, each with a booking and a real chat message already exchanged between its own customer and provider. */
    private function makeTwoWorldsWithChat(): array
    {
        $mine = $this->makeBookingScenario('assigned');
        $other = $this->makeBookingScenario('assigned');

        $mine['message'] = $this->makeMessage($mine['booking'], $mine['customer']->id, $mine['provider']->user_id);
        $other['message'] = $this->makeMessage($other['booking'], $other['customer']->id, $other['provider']->user_id);

        return [$mine, $other];
    }

    // ============================== A/B/C: permission gate ==============================

    public function test_a_chat_view_permission_denied(): void
    {
        [$mine] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(ChatManage::class)->assertForbidden();
    }

    public function test_b_chat_view_permission_allowed(): void
    {
        [$mine] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'global');

        Livewire::actingAs($actor)->test(ChatManage::class)->assertOk();
    }

    public function test_c_super_admin_bypasses_the_gate_like_every_other_screen(): void
    {
        [$mine, $other] = $this->makeTwoWorldsWithChat();
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(ChatManage::class)->assertOk();
        $codes = $component->viewData('bookings')->pluck('code')->all();

        $this->assertContains($mine['booking']->code, $codes);
        $this->assertContains($other['booking']->code, $codes);
    }

    // ============================== D/E: row-level scope ==============================

    public function test_d_zone_scoped_admin_can_view_an_in_scope_conversation(): void
    {
        [$mine] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(ChatManage::class);
        $codes = $component->viewData('bookings')->pluck('code')->all();

        $this->assertContains($mine['booking']->code, $codes);
    }

    public function test_e_zone_scoped_admin_cannot_see_another_zones_conversation_in_the_list(): void
    {
        [$mine, $other] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(ChatManage::class);
        $codes = $component->viewData('bookings')->pluck('code')->all();

        $this->assertNotContains($other['booking']->code, $codes);
    }

    // ============================== F: direct booking-id manipulation ==============================

    public function test_f_direct_booking_id_manipulation_cannot_bypass_scope(): void
    {
        [$mine, $other] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'zone', $mine['zone']->id);

        // Never clicked the (never-rendered) "View" link for the other
        // zone's booking -- calling the Livewire action directly with its
        // real id, exactly as a tampered wire request would.
        Livewire::actingAs($actor)->test(ChatManage::class)
            ->call('selectBooking', $other['booking']->id)
            ->assertStatus(404);
    }

    public function test_f_a_legitimate_in_scope_selection_still_works(): void
    {
        [$mine] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(ChatManage::class)
            ->call('selectBooking', $mine['booking']->id)
            ->assertOk();

        $this->assertSame($mine['booking']->id, $component->viewData('booking')->id);
        $this->assertTrue($component->viewData('messages')->contains('id', $mine['message']->id));
    }

    // ============================== G/H: attachment security ==============================

    public function test_g_attachment_retrieval_requires_chat_view(): void
    {
        $scenario = $this->makeBookingScenario();
        $message = $this->makeMessage($scenario['booking'], $scenario['customer']->id, $scenario['provider']->user_id, ['attachment_url' => 'chat/'.$scenario['booking']->id.'/test.jpg']);
        \Illuminate\Support\Facades\Storage::disk('local')->put($message->attachment_url, 'fake-image-bytes');

        // A genuine admin-panel actor (holds a role_assignment, so it clears
        // the panel's front-door EnsureHasAdminAccess gate) who simply does
        // not hold chat.view -- isolates the controller's own permission
        // check from the outer "are you an admin at all" gate.
        $actor = $this->makeUserWithPermission('payouts.manage', 'global');

        $this->actingAs($actor)->get(route('admin.chat.attachments.show', $message->id))->assertNotFound();
    }

    public function test_h_attachment_retrieval_cannot_cross_franchise_scope(): void
    {
        [$mine, $other] = $this->makeTwoWorldsWithChat();
        $otherMessage = $this->makeMessage($other['booking'], $other['customer']->id, $other['provider']->user_id, ['attachment_url' => 'chat/'.$other['booking']->id.'/test.jpg']);
        \Illuminate\Support\Facades\Storage::disk('local')->put($otherMessage->attachment_url, 'fake-image-bytes');

        $actor = $this->makeUserWithPermission('chat.view', 'zone', $mine['zone']->id);

        // A guessed message id belonging to a DIFFERENT zone's booking must
        // never expose the file, even though the actor genuinely holds
        // chat.view somewhere else.
        $this->actingAs($actor)->get(route('admin.chat.attachments.show', $otherMessage->id))->assertNotFound();
    }

    public function test_h_attachment_retrieval_succeeds_for_an_in_scope_message(): void
    {
        $scenario = $this->makeBookingScenario();
        $message = $this->makeMessage($scenario['booking'], $scenario['customer']->id, $scenario['provider']->user_id, ['attachment_url' => 'chat/'.$scenario['booking']->id.'/test.jpg']);
        \Illuminate\Support\Facades\Storage::disk('local')->put($message->attachment_url, 'fake-image-bytes');

        $actor = $this->makeUserWithPermission('chat.view', 'zone', $scenario['zone']->id);

        $this->actingAs($actor)->get(route('admin.chat.attachments.show', $message->id))->assertOk();
    }

    public function test_h_attachment_retrieval_404s_for_a_message_with_no_attachment(): void
    {
        $scenario = $this->makeBookingScenario();
        $message = $this->makeMessage($scenario['booking'], $scenario['customer']->id, $scenario['provider']->user_id);
        $actor = $this->makeUserWithPermission('chat.view', 'global');

        $this->actingAs($actor)->get(route('admin.chat.attachments.show', $message->id))->assertNotFound();
    }

    // ============================== I: activity log ==============================

    public function test_i_viewing_a_conversation_writes_one_activity_log_entry(): void
    {
        [$mine] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'global');

        $this->assertSame(0, ActivityLog::count());

        Livewire::actingAs($actor)->test(ChatManage::class)->call('selectBooking', $mine['booking']->id);

        $this->assertSame(1, ActivityLog::count());
        $log = ActivityLog::first();
        $this->assertSame($actor->id, $log->causer_id);
        $this->assertSame(\App\Models\Booking::class, $log->subject_type);
        $this->assertSame($mine['booking']->id, $log->subject_id);
        $this->assertStringContainsString($mine['booking']->code, $log->description);
        // Never logs message contents or attachment paths -- only the fact
        // that the conversation was opened, and by whom.
        $this->assertStringNotContainsString($mine['message']->message, $log->description);
    }

    public function test_i_re_opening_the_same_conversation_in_a_new_request_logs_again(): void
    {
        [$mine] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'global');

        Livewire::actingAs($actor)->test(ChatManage::class)->call('selectBooking', $mine['booking']->id);
        Livewire::actingAs($actor)->test(ChatManage::class)->call('selectBooking', $mine['booking']->id);

        // Two genuinely separate "open" actions -> two log rows, not
        // deduplicated -- each real view is its own auditable event.
        $this->assertSame(2, ActivityLog::count());
    }

    public function test_i_denied_selection_does_not_write_an_activity_log_entry(): void
    {
        [$mine, $other] = $this->makeTwoWorldsWithChat();
        $actor = $this->makeUserWithPermission('chat.view', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(ChatManage::class)->call('selectBooking', $other['booking']->id);

        $this->assertSame(0, ActivityLog::count(), 'a rejected, out-of-scope access attempt is not a real view and should not be logged as one');
    }

    // ============================== K: TECH-3 timezone integration ==============================

    public function test_k_message_timestamps_render_in_the_bookings_franchise_local_time(): void
    {
        $scenario = $this->makeBookingScenario();
        $message = $this->makeMessage($scenario['booking'], $scenario['customer']->id, $scenario['provider']->user_id);
        // 2026-01-15 19:00:00 UTC == 2026-01-16 00:30:00 IST (Asia/Kolkata) -- makeFranchiseTree()'s own real fixture timezone, same boundary case TimezoneDisplayTest uses.
        $message->forceFill(['created_at' => \Illuminate\Support\Carbon::create(2026, 1, 15, 19, 0, 0, 'UTC')])->save();
        $actor = $this->makeUserWithPermission('chat.view', 'global');

        Livewire::actingAs($actor)->test(ChatManage::class)
            ->call('selectBooking', $scenario['booking']->id)
            ->assertSee('16 Jan 2026, 12:30:00 AM');
    }

    // ============================== N+1 / performance ==============================

    public function test_conversation_list_does_not_n_plus_one_across_multiple_bookings(): void
    {
        foreach (range(1, 3) as $i) {
            $scenario = $this->makeBookingScenario('assigned');
            $this->makeMessage($scenario['booking'], $scenario['customer']->id, $scenario['provider']->user_id);
        }
        $admin = $this->makeSuperAdmin();

        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(ChatManage::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $queryCount);
    }

    public function test_thread_view_does_not_n_plus_one_across_multiple_messages(): void
    {
        $scenario = $this->makeBookingScenario('assigned');
        foreach (range(1, 5) as $i) {
            $this->makeMessage($scenario['booking'], $scenario['customer']->id, $scenario['provider']->user_id);
        }
        $admin = $this->makeSuperAdmin();

        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(ChatManage::class)->call('selectBooking', $scenario['booking']->id);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }

    // ============================== Hard scope boundary: no moderation capability exists ==============================

    /** Confirms this pass genuinely added nothing beyond viewing -- no chat.moderate permission was ever seeded. */
    public function test_no_chat_moderate_permission_exists(): void
    {
        $this->assertSame(0, DB::table('permissions')->where('slug', 'chat.moderate')->count());
        $this->assertSame(1, DB::table('permissions')->where('slug', 'chat.view')->count());
    }

    public function test_admin_manage_component_exposes_no_send_or_delete_action(): void
    {
        $this->assertFalse(method_exists(ChatManage::class, 'send'), 'Option A is read-only -- no send action should exist on the admin screen');
        $this->assertFalse(method_exists(ChatManage::class, 'deleteMessage'), 'Option A is read-only -- no delete action should exist on the admin screen');
        $this->assertFalse(method_exists(ChatManage::class, 'hideMessage'), 'Option A is read-only -- no hide/moderate action should exist on the admin screen');
    }
}
