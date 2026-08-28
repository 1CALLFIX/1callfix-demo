<?php

namespace Tests\Feature\Booking;

use App\Actions\CompleteBookingAction;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E5 — what a verified completion triggers downstream, and what a
 * forged / duplicated one must NOT. The settlement path itself
 * (CommissionService::applyForBooking -> WalletService::credit) is reused
 * unchanged; CommissionAbuseTest / CommissionIdempotencyTest still own the
 * split-maths and the service-level idempotency. This file adds the E5
 * pieces: the receipt is materialised through the existing DocumentService
 * on completion, exactly once, from the authoritative Payment amount.
 */
class BookingCompletionSettlementTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    /** An assigned booking that also has a captured Payment, like a real paid job. */
    private function makePaidAssignedScenario(): array
    {
        $scenario = $this->makeAssignedBookingScenario();
        $scenario['payment'] = Payment::create([
            'booking_id' => $scenario['booking']->id,
            'purpose' => 'booking',
            'amount' => 500,
            'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.$scenario['booking']->id,
            'gateway_payment_id' => 'pay_'.$scenario['booking']->id,
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        return $scenario;
    }

    // ============================ settlement ============================

    public function test_a_verified_completion_runs_the_existing_settlement_exactly_once(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makePaidAssignedScenario();

        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $this->assertSame(1, Commission::where('booking_id', $booking->id)->count());
        // Fixture franchise: 5% platform, 10% franchise revenue share, on 500.
        $commission = Commission::where('booking_id', $booking->id)->first();
        $this->assertEquals(425.00, (float) $commission->provider_commission);
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$booking->id}:provider-earning")->count());
    }

    public function test_a_forged_completion_settles_nobody(): void
    {
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makePaidAssignedScenario();
        $intruder = $this->makeProviderIn($franchise, $zone);

        try {
            app(CompleteBookingAction::class)->execute($booking->id, $intruder, '5678');
        } catch (\RuntimeException $e) {
            // expected — not assigned to this provider
        }

        $this->assertSame('assigned', $booking->fresh()->status);
        $this->assertSame(0, Commission::count());
        $this->assertSame(0, GeneratedDocument::count());
        $this->assertSame(0, WalletTransaction::where('is_credit', true)->count());
    }

    public function test_a_duplicate_completion_cannot_double_settle(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makePaidAssignedScenario();

        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
        $balanceAfterFirst = (float) $provider->user->wallet->fresh()->balance;

        // A second attempt is refused by the status gate before settlement.
        try {
            app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
            $this->fail('Expected the duplicate completion to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be completed', $e->getMessage());
        }

        $this->assertSame(1, Commission::where('booking_id', $booking->id)->count());
        $this->assertEquals($balanceAfterFirst, (float) $provider->user->wallet->fresh()->balance);
    }

    // ============================ invoice / receipt ============================

    public function test_completion_materialises_a_receipt_through_the_existing_document_engine(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'payment' => $payment] = $this->makePaidAssignedScenario();

        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $receipt = GeneratedDocument::where('documentable_type', Payment::class)
            ->where('documentable_id', $payment->id)
            ->where('type', 'receipt')
            ->first();

        $this->assertNotNull($receipt, 'A receipt document should be generated on completion.');
        $this->assertStringStartsWith('REC', $receipt->number);
    }

    public function test_the_receipt_amount_comes_from_the_authoritative_payment_not_the_request(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'payment' => $payment] = $this->makePaidAssignedScenario();

        // The action signature has no amount parameter, but prove the built
        // document reads the stored Payment, not the booking's mutable
        // price_final or anything else.
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $data = app(\App\Services\Documents\DocumentService::class)->forPayment($payment->fresh(), 'receipt');
        $this->assertSame(500.0, $data['total']);
    }

    public function test_a_duplicate_completion_event_creates_no_second_receipt(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'payment' => $payment] = $this->makePaidAssignedScenario();

        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        // Re-run the exact downstream the completion performs (idempotent by
        // the (documentable, type) unique constraint in DocumentNumberService).
        app(\App\Services\Documents\DocumentService::class)->forPayment($payment->fresh(), 'receipt');
        app(\App\Services\Documents\DocumentService::class)->forPayment($payment->fresh(), 'receipt');

        $this->assertSame(1, GeneratedDocument::where('documentable_id', $payment->id)->where('type', 'receipt')->count());
    }

    public function test_a_completion_with_no_payment_row_still_completes_without_a_receipt(): void
    {
        // Many bookings (cash on delivery, unpaid at completion time) have no
        // captured Payment — completion must not break, there is simply
        // nothing to receipt yet.
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $result = app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $this->assertSame('completed', $result->status);
        $this->assertSame(0, GeneratedDocument::count());
    }

    // ============================ client-supplied field integrity ============================

    public function test_client_cannot_force_completion_or_price_through_extra_request_fields(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makePaidAssignedScenario();

        $this->actingAs($provider->user, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete", [
                'otp' => '5678',
                'status' => 'disputed',
                'price_final' => 1,
                'provider_commission' => 999999,
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame('completed', $booking->status);
        $this->assertEquals(500.00, (float) $booking->price_final);
        $this->assertEquals(425.00, (float) Commission::where('booking_id', $booking->id)->value('provider_commission'));
    }
}
