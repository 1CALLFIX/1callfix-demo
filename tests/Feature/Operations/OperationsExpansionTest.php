<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\Health;
use App\Models\ActivityLog;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\ScheduledTaskRun;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Regression coverage for the Phase 10 "Operations expansion" additions to
 * the existing Operations/Troubleshoot screen (see OperationsHealthTest for
 * the original failed-jobs/notification-failures coverage): reconciliation
 * warnings, dispatch health, stuck bookings, scheduled-task run history, and
 * the payment webhook log browser + reprocess action. Same
 * RbacTestHelpers/BookingFixtureHelpers fixtures every other Feature suite
 * in this codebase already uses.
 */
class OperationsExpansionTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_reconciliation_flags_paid_booking_without_captured_payment(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->update(['payment_status' => 'paid']);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee('Booking #'.$scenario['booking']->id);
    }

    public function test_reconciliation_flags_completed_booking_without_commission(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $scenario = $this->makeBookingScenario('completed');

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee('Completed bookings without commission');

        $this->assertDatabaseMissing('commissions', ['booking_id' => $scenario['booking']->id]);
    }

    public function test_reconciliation_does_not_flag_a_booking_with_matching_commission(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $scenario = $this->makeBookingScenario('completed');
        Commission::create([
            'booking_id' => $scenario['booking']->id,
            'provider_commission' => 400, 'franchise_commission' => 50, 'platform_commission' => 50,
        ]);

        $component = Livewire::actingAs($actor)->test(Health::class)->assertOk();
        $component->assertViewHas('reconciliation', function (array $reconciliation) use ($scenario) {
            return ! $reconciliation['completed_bookings_without_commission']->contains('id', $scenario['booking']->id);
        });
    }

    public function test_reconciliation_flags_wallet_balance_mismatch(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $customer = $this->makeCustomer();
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 500]);
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 100, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test-ref', 'status' => 'successful']);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee('Wallet balance mismatches');
    }

    public function test_dispatch_health_flags_stale_offer(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $scenario = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $scenario['booking']->id, 'provider_id' => $scenario['provider']->id,
            'status' => 'notified', 'distance_km' => 2, 'notified_at' => now()->subMinutes(10),
        ]);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee('Booking #'.$scenario['booking']->id);
    }

    public function test_stuck_booking_service_flags_booking_past_threshold(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['booking']->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $component = Livewire::actingAs($actor)->test(Health::class)->assertOk();
        $component->assertViewHas('stuckBookings', function ($stuckBookings) use ($scenario) {
            return $stuckBookings->contains(fn ($row) => $row['booking']->id === $scenario['booking']->id);
        });
    }

    public function test_scheduled_task_runs_render(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        ScheduledTaskRun::create([
            'command' => 'kyc:send-reminders', 'status' => 'success',
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee('kyc:send-reminders');
    }

    public function test_webhook_log_renders_and_filters(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        PaymentWebhookLog::create(['event' => 'payment.captured', 'signature_valid' => true, 'processed' => true, 'outcome' => 'captured', 'payload' => []]);
        PaymentWebhookLog::create(['event' => 'payment.captured', 'signature_valid' => true, 'processed' => false, 'outcome' => 'unmatched_order', 'payload' => []]);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee('unmatched_order')
            ->set('webhookFilter', 'unprocessed')
            ->assertSee('unmatched_order')
            ->assertDontSee('already_processed');
    }

    public function test_reprocess_webhook_denied_without_operations_manage(): void
    {
        $actor = $this->makeUserWithPermission('operations.view', 'global');
        $log = PaymentWebhookLog::create(['event' => 'payment.captured', 'signature_valid' => true, 'processed' => false, 'outcome' => 'unmatched_order', 'payload' => ['payload' => ['payment' => ['entity' => ['order_id' => 'order_missing']]]]]);

        Livewire::actingAs($actor)->test(Health::class)->call('reprocessWebhook', $log->id);

        $this->assertDatabaseHas('payment_webhook_logs', ['id' => $log->id, 'outcome' => 'unmatched_order']);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_reprocess_webhook_updates_outcome_and_logs_activity_with_operations_manage(): void
    {
        $actor = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($actor, 'operations.view');

        $scenario = $this->makeBookingScenario('searching_provider');
        $payment = Payment::create([
            'booking_id' => $scenario['booking']->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_abc123', 'status' => 'pending',
        ]);
        $log = PaymentWebhookLog::create([
            'event' => 'payment.captured', 'signature_valid' => true, 'processed' => false, 'outcome' => 'unmatched_order',
            'gateway_order_id' => 'order_abc123',
            'payload' => ['payload' => ['payment' => ['entity' => ['order_id' => 'order_abc123', 'id' => 'pay_abc123']]]],
        ]);

        Livewire::actingAs($actor)->test(Health::class)
            ->call('reprocessWebhook', $log->id)
            ->assertOk();

        $this->assertDatabaseHas('payment_webhook_logs', ['id' => $log->id, 'outcome' => 'captured', 'processed' => 1]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'captured']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => PaymentWebhookLog::class, 'subject_id' => $log->id]);
    }

    public function test_retry_job_records_activity_log_with_operations_manage(): void
    {
        $actor = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($actor, 'operations.view');

        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ServiceMatchingJob', 'data' => []]),
            'exception' => "RuntimeException\n#0 {main}", 'failed_at' => now(),
        ]);
        $uuid = \Illuminate\Support\Facades\DB::table('failed_jobs')->value('uuid');

        Livewire::actingAs($actor)->test(Health::class)->call('retryJob', $uuid);

        $this->assertDatabaseHas('activity_log', ['subject_type' => 'failed_job', 'causer_id' => $actor->id]);
    }
}
