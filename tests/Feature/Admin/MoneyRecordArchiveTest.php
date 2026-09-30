<?php

namespace Tests\Feature\Admin;

use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-ADMIN-ROWACTIONS-001 — archive ("Delete") for money records that never moved
 * money, with the guards that keep real financial history untouchable.
 */
class MoneyRecordArchiveTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function payment(string $status, ?string $orderId = null, ?\DateTimeInterface $createdAt = null): Payment
    {
        $scenario = $this->makeBookingScenario();
        $p = Payment::create([
            'booking_id' => $scenario['booking']->id, 'purpose' => 'booking', 'amount' => 1,
            'gateway' => 'razorpay', 'gateway_order_id' => $orderId ?? 'order_'.uniqid(), 'status' => $status,
        ]);
        if ($createdAt) {
            $p->forceFill(['created_at' => $createdAt])->save();
        }

        return $p;
    }

    public function test_failed_and_stale_pending_payments_can_be_archived_and_restored_by_super_admin(): void
    {
        $failed = $this->payment('failed');
        $stalePending = $this->payment('pending', null, now()->subDays(2));
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(PaymentsIndex::class);

        foreach ([$failed, $stalePending] as $p) {
            $c->call('askArchive', $p->id)->call('confirmArchive');
            $this->assertSoftDeleted('payments', ['id' => $p->id]);
        }

        $c->set('statusFilter', 'archived');
        $this->assertEqualsCanonicalizing([$failed->id, $stalePending->id], $c->viewData('payments')->pluck('id')->all());

        $c->call('restoreRow', $failed->id);
        $this->assertNotSoftDeleted('payments', ['id' => $failed->id]);
        $this->assertTrue(ActivityLog::where('subject_id', $failed->id)->where('description', 'archived')->exists());
    }

    public function test_real_money_records_and_fresh_pending_orders_cannot_be_archived(): void
    {
        $captured = $this->payment('captured');
        $refunded = $this->payment('refunded');
        $freshPending = $this->payment('pending'); // could still be paid right now
        $admin = $this->makeSuperAdmin();

        foreach ([$captured, $refunded, $freshPending] as $p) {
            // A 403 ends the component's session, so use a fresh instance per attempt.
            Livewire::actingAs($admin)->test(PaymentsIndex::class)->call('askArchive', $p->id)->assertForbidden();
            $this->assertNotSoftDeleted('payments', ['id' => $p->id]);
        }
    }

    public function test_only_super_admin_can_archive_payments(): void
    {
        $failed = $this->payment('failed');
        $viewer = $this->makeUserWithPermission('payments.view', 'global');

        Livewire::actingAs($viewer)->test(PaymentsIndex::class)->call('askArchive', $failed->id)->assertForbidden();
        $this->assertNotSoftDeleted('payments', ['id' => $failed->id]);
    }

    public function test_a_late_capture_for_an_archived_order_still_lands_and_revives_the_payment(): void
    {
        $payment = $this->payment('pending', 'order_LATE1', now()->subDays(3));
        $payment->delete();
        $this->assertSoftDeleted('payments', ['id' => $payment->id]);

        $result = app(RazorpayWebhookHandler::class)->handleCaptured(['payload' => ['payment' => ['entity' => ['order_id' => 'order_LATE1', 'id' => 'pay_LATE1']]]]);

        $this->assertNotSame('unhandled_event', $result['outcome']);
        $fresh = Payment::find($payment->id);
        $this->assertNotNull($fresh, 'the archived row was revived');
        $this->assertSame('captured', $fresh->status);
    }

    public function test_payout_and_subscription_archive_guards_and_permissions(): void
    {
        $viewerOnly = $this->makeUserWithNoPermissions();
        // No manage permission: both screens refuse to even mount, so nothing can be archived.
        Livewire::actingAs($viewerOnly)->test(PayoutsManage::class)->assertForbidden();
        Livewire::actingAs($viewerOnly)->test(SubscriptionsIndex::class)->assertForbidden();
    }
}
