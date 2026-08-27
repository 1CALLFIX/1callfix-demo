<?php

namespace Tests\Feature\Finance;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase D — commission cannot be taken for work that was not done, and cannot
 * be taken twice for work that was.
 *
 * ── What actually makes commission payable ────────────────────────────────
 * Established by reading the code, not assumed: there is no webhook and no
 * scheduled job in this path. `CommissionService::applyForBooking()` has
 * exactly one production caller, `CompleteBookingAction::execute()`, which is
 * reached from `POST /api/bookings/{id}/complete`
 * (`API\DispatchController::complete()`). Before it will pay anyone, all four
 * of these must hold, inside a transaction on a locked booking row:
 *
 *   1. the caller's user has a Provider profile at all;
 *   2. `booking.provider_id` is THAT provider;
 *   3. `booking.status` is one of assigned / provider_en_route / in_progress;
 *   4. the submitted OTP matches `booking.completion_otp` — a code the
 *      customer reads out, so it evidences the work really happened.
 *
 * Each test below removes exactly one of those and proves nothing is paid.
 * Idempotency is then proved at the HTTP level: the second identical request
 * is refused by the status FSM, and `CommissionIdempotencyTest` separately
 * proves the service itself short-circuits on an existing Commission row, so
 * both layers are covered rather than only the inner one.
 */
class CommissionAbuseTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** Nothing was paid to anyone, by any route. */
    private function assertNothingWasPaid(): void
    {
        $this->assertSame(0, Commission::count(), 'No commission row may exist.');
        $this->assertSame(0, WalletTransaction::where('is_credit', true)->count(), 'No wallet may have been credited.');
    }

    // ==================== Commission before completion ====================

    public function test_an_assigned_but_uncompleted_booking_has_paid_nobody(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->assertSame('assigned', $scenario['booking']->status);
        $this->assertNothingWasPaid();
    }

    public function test_completion_with_a_wrong_otp_pays_no_commission(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->actingAs($scenario['provider']->user, 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/complete", ['otp' => '0000'])
            ->assertStatus(409);

        $this->assertSame('assigned', $scenario['booking']->fresh()->status, 'A wrong OTP must not advance the booking.');
        $this->assertNothingWasPaid();
    }

    /**
     * The forged-completion case: a real, active provider account that simply
     * is not the one this booking was assigned to, submitting the correct
     * OTP. Knowing the code must not be enough.
     */
    public function test_a_provider_who_was_not_assigned_cannot_complete_the_booking_even_with_the_right_otp(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        $intruder = $this->makeProviderIn($scenario['franchise'], $scenario['zone']);

        $this->actingAs($intruder->user, 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/complete", ['otp' => '5678'])
            ->assertStatus(409);

        $this->assertSame('assigned', $scenario['booking']->fresh()->status);
        $this->assertNothingWasPaid();
    }

    public function test_the_customer_cannot_complete_their_own_booking_to_release_the_payout(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/complete", ['otp' => '5678'])
            ->assertStatus(403);

        $this->assertNothingWasPaid();
    }

    public function test_an_unauthenticated_completion_request_is_rejected(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->postJson("/api/bookings/{$scenario['booking']->id}/complete", ['otp' => '5678'])
            ->assertStatus(401);

        $this->assertNothingWasPaid();
    }

    /**
     * A booking that never reached an assigned state cannot be completed
     * either — the status gate, not just the OTP, is doing work.
     */
    public function test_a_booking_still_searching_for_a_provider_cannot_be_completed(): void
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['booking']->update(['provider_id' => $scenario['provider']->id, 'completion_otp' => '5678']);

        $this->actingAs($scenario['provider']->user, 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/complete", ['otp' => '5678'])
            ->assertStatus(409);

        $this->assertNothingWasPaid();
    }

    // ==================== The legitimate path, then repeated ====================

    public function test_a_legitimate_completion_pays_the_provider_exactly_once(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->actingAs($scenario['provider']->user, 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/complete", ['otp' => '5678'])
            ->assertOk()
            ->assertJsonPath('booking.status', 'completed');

        $commission = Commission::where('booking_id', $scenario['booking']->id)->firstOrFail();

        // Fixture franchise: platform_fee_percent 5, revenue_share 10, on 500.
        $this->assertEquals(25.00, (float) $commission->platform_commission);
        $this->assertEquals(50.00, (float) $commission->franchise_commission);
        $this->assertEquals(425.00, (float) $commission->provider_commission);

        $this->assertEquals(425.00, (float) $scenario['provider']->user->wallet->fresh()->balance);
    }

    /**
     * The replay. The same request, sent again, must change nothing: the FSM
     * refuses it because the booking is already completed, so the payout is
     * never reached a second time.
     */
    public function test_replaying_the_completion_request_does_not_pay_a_second_time(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        $url = "/api/bookings/{$scenario['booking']->id}/complete";

        $this->actingAs($scenario['provider']->user, 'sanctum')->postJson($url, ['otp' => '5678'])->assertOk();

        $balanceAfterFirst = (float) $scenario['provider']->user->wallet->fresh()->balance;
        $this->assertEquals(425.00, $balanceAfterFirst);

        $this->actingAs($scenario['provider']->user, 'sanctum')->postJson($url, ['otp' => '5678'])->assertStatus(409);
        $this->actingAs($scenario['provider']->user, 'sanctum')->postJson($url, ['otp' => '5678'])->assertStatus(409);

        $this->assertSame(1, Commission::where('booking_id', $scenario['booking']->id)->count());
        $this->assertEquals($balanceAfterFirst, (float) $scenario['provider']->user->wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$scenario['booking']->id}:provider-earning")->count());
    }

    /**
     * Commission is computed from the booking's own persisted final price,
     * not from anything a client sends. `price_final` is set by
     * CompleteBookingAction itself from `price_quoted` plus approved extras.
     */
    public function test_the_payout_is_computed_from_the_stored_price_not_from_the_request_body(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->actingAs($scenario['provider']->user, 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/complete", [
                'otp' => '5678',
                'price_final' => 99999,
                'provider_commission' => 99999,
                'amount' => 99999,
            ])
            ->assertOk();

        $booking = Booking::findOrFail($scenario['booking']->id);
        $this->assertEquals(500.00, (float) $booking->price_final);
        $this->assertEquals(425.00, (float) Commission::where('booking_id', $booking->id)->value('provider_commission'));
    }
}
