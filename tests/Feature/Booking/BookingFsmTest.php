<?php

namespace Tests\Feature\Booking;

use App\Actions\AdminCancelBookingAction;
use App\Actions\AdminReassignBookingAction;
use App\Actions\CompleteBookingAction;
use App\Actions\StartBookingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Priority 2 required test areas B (Booking FSM), C (Provider acceptance/
 * self-completion), G (Admin reassignment). Every transition Action already
 * enforces the rule it claims to (locked/authorized/auditable) — this locks
 * each one in with a real test.
 */
class BookingFsmTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    // ============================= StartBookingAction =============================

    public function test_correct_start_otp_transitions_to_in_progress(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();

        $result = app(StartBookingAction::class)->execute($booking->id, '1234');

        $this->assertSame('in_progress', $result->status);
        $this->assertDatabaseHas('booking_status_history', ['booking_id' => $booking->id, 'status' => 'in_progress']);
    }

    public function test_wrong_start_otp_is_rejected(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect start OTP');
        app(StartBookingAction::class)->execute($booking->id, '0000');
    }

    public function test_cannot_start_a_booking_still_searching_for_a_provider(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be started');
        app(StartBookingAction::class)->execute($booking->id, '1234');
    }

    public function test_cannot_start_an_already_in_progress_booking_twice(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();
        app(StartBookingAction::class)->execute($booking->id, '1234');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be started');
        app(StartBookingAction::class)->execute($booking->id, '1234');
    }

    // ============================= CompleteBookingAction (Provider self-completion) =============================

    public function test_correct_completion_otp_completes_the_booking(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $result = app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $this->assertSame('completed', $result->status);
        $this->assertNotNull($result->completed_at);
        $this->assertEquals(500, $result->price_final);
        $this->assertDatabaseHas('booking_status_history', ['booking_id' => $booking->id, 'status' => 'completed']);
        $this->assertDatabaseHas('commissions', ['booking_id' => $booking->id]);
    }

    public function test_wrong_completion_otp_is_rejected(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect completion OTP');
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '0000');
    }

    public function test_a_different_providers_booking_cannot_be_completed(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $unrelatedProvider = $this->makeProviderIn($otherFranchise, $otherZone);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not assigned to you');
        app(CompleteBookingAction::class)->execute($booking->id, $unrelatedProvider, '5678');
    }

    public function test_duplicate_completion_is_rejected_and_does_not_double_apply_commission(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be completed');
        try {
            app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
        } finally {
            $this->assertSame(1, \App\Models\Commission::where('booking_id', $booking->id)->count(), 'A rejected duplicate completion must not produce a second commission row.');
        }
    }

    public function test_a_cancelled_booking_cannot_be_completed(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $booking->update(['status' => 'cancelled']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be completed');
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
    }

    public function test_approved_extra_work_items_are_added_to_the_final_price(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $booking->extraItems()->create(['description' => 'Extra part', 'amount' => 150, 'status' => 'approved', 'added_by_provider_id' => $provider->id]);
        $booking->extraItems()->create(['description' => 'Rejected extra', 'amount' => 999, 'status' => 'rejected', 'added_by_provider_id' => $provider->id]);

        $result = app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $this->assertEquals(650, $result->price_final, 'price_final must be base + only APPROVED extras.');
    }

    // ============================= AdminCancelBookingAction =============================

    public function test_admin_can_cancel_a_pre_service_booking(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        $result = app(AdminCancelBookingAction::class)->execute($booking->id, 'Customer requested cancellation');

        $this->assertSame('cancelled', $result->status);
        $this->assertSame('Customer requested cancellation', $result->cancellation_note);
    }

    public function test_a_completed_booking_cannot_be_cancelled(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already completed');
        app(AdminCancelBookingAction::class)->execute($booking->id, 'too late');
    }

    public function test_an_already_cancelled_booking_cannot_be_cancelled_again(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        app(AdminCancelBookingAction::class)->execute($booking->id, 'first cancel');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already cancelled');
        app(AdminCancelBookingAction::class)->execute($booking->id, 'second cancel');
    }

    // ============================= AdminReassignBookingAction =============================

    public function test_admin_reassign_moves_the_booking_to_a_new_provider(): void
    {
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makeAssignedBookingScenario();
        $newProvider = $this->makeProviderIn($franchise, $zone);

        $result = app(AdminReassignBookingAction::class)->execute($booking->id, $newProvider->id, 'original unresponsive');

        $this->assertSame($newProvider->id, $result->provider_id);
        $this->assertDatabaseHas('booking_status_history', ['booking_id' => $booking->id]);
    }

    public function test_reassigning_to_a_different_provider_clears_a_stale_worker_assignment(): void
    {
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makeAssignedBookingScenario();
        $workerUser = \App\Models\User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Worker', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        $staleWorker = \App\Models\FieldWorker::create([
            'user_id' => $workerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true,
        ]);
        $booking->update(['assigned_worker_id' => $staleWorker->id]);
        $newProvider = $this->makeProviderIn($franchise, $zone);

        $result = app(AdminReassignBookingAction::class)->execute($booking->id, $newProvider->id);

        $this->assertNull($result->assigned_worker_id, 'A worker assigned under the OLD provider must not carry over to a different one.');
    }

    public function test_reassigning_a_pending_booking_generates_otps_and_moves_to_assigned(): void
    {
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('pending');
        $provider = $this->makeProviderIn($franchise, $zone);

        $result = app(AdminReassignBookingAction::class)->execute($booking->id, $provider->id);

        $this->assertSame('assigned', $result->status);
        $this->assertNotEmpty($result->start_otp);
        $this->assertNotEmpty($result->completion_otp);
    }
}
