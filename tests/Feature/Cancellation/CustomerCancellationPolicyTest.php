<?php

namespace Tests\Feature\Cancellation;

use App\Actions\CustomerCancelBookingAction;
use App\Actions\DisputeInterimDeclarationAction;
use App\Actions\MarkSparesAvailableAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Actions\ProposeExtraWorkAction;
use App\Actions\ResolveInterimDisputeAction;
use App\Actions\ResumeBookingAction;
use App\Actions\WaiveCancellationChargeAction;
use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use App\Services\Cancellation\CancellationBlockedException;
use App\Services\Cancellation\CancellationPolicy;
use App\Services\Cancellation\CancellationQuoteChangedException;
use App\Services\Cancellation\CancellationSweepService;
use App\Services\Cancellation\InterimChargeCalculator;
use App\Services\Cancellation\SparesDelayClock;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-CANCEL-POLICY-001 — customer cancellation policy: mid-work lock, spares-delay exit, interim-work charge,
 * settle-before-cancel for cash, provider payout, idempotency. See docs/CANCELLATION_POLICY_DESIGN.md.
 */
class CustomerCancellationPolicyTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function inProgress(): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    private function hold(Booking $booking, array $over = []): Booking
    {
        return app(PlaceBookingOnHoldAction::class)->execute($booking->id, 'awaiting_spares', 'Waiting for the part', array_merge([
            'progress_percent' => 40,
            'parts_fitted_cost' => 0,
            'sourced_by' => 'provider',
            'expected_at' => now()->addDays(2)->toDateString(),
        ], $over));
    }

    private function decision(Booking $booking): array
    {
        return app(CancellationPolicy::class)->evaluate($booking->fresh());
    }

    private function cancel(Booking $booking, ?string $token = null): array
    {
        return app(CustomerCancelBookingAction::class)->execute($booking->id, $booking->customer_id, 'Changed my mind', $token);
    }

    private function token(Booking $booking): ?string
    {
        return app(CustomerCancelBookingAction::class)->quote($booking->fresh())['token'];
    }

    private function mockGateway(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('identifier')->andReturn('razorpay');
        $mock->shouldReceive('checkoutKeyId')->andReturn('rzp_test_key');
        $this->app->instance(PaymentGateway::class, $mock);

        return $mock;
    }

    private function seedWallet(User $user, float $amount): void
    {
        app(WalletService::class)->credit($user, $amount, 'seed', 'seed:'.$user->id);
    }

    private function backdate(int $days, callable $run): void
    {
        Carbon::setTestNow(now()->subDays($days));
        $run();
        Carbon::setTestNow();
    }

    // ------------------------------------------------------------------ matrix

    public function test_before_work_the_customer_can_cancel_free_within_the_window(): void
    {
        foreach (['pending', 'searching_provider'] as $status) {
            $s = $this->makeBookingScenario($status);
            $d = $this->decision($s['booking']);

            $this->assertTrue($d['allowed'], $status);
            $this->assertSame(0.0, $d['charge']);
        }

        $s = $this->makeAssignedBookingScenario();
        $this->assertTrue($this->decision($s['booking'])['allowed']);
        $this->assertSame('cancelled', $this->cancel($s['booking'])['booking']->status);
    }

    public function test_assigned_booking_pays_the_configured_assigned_fee_not_a_time_based_one(): void
    {
        // The old elapsed-time fee no longer applies at this stage: only cancellation.assigned_fee (read from the
        // booking's snapshot) does. An admin choosing a value is a business decision — here the test sets one.
        Setting::set('cancellation.fee_type', 'flat');
        Setting::set('cancellation.fee_value', '999'); // legacy time-based keys must be ignored for the customer
        Setting::set('cancellation.assigned_fee', '50');
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $s['booking']->update(['payment_status' => 'paid']);
        Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);

        $d = $this->decision($s['booking']);
        $this->assertTrue($d['allowed']);
        $this->assertSame(50.0, $d['charge']);

        $r = $this->cancel($s['booking'], $this->token($s['booking']));
        $this->assertSame('cancelled', $r['outcome']);
        $this->assertEquals(50, $r['booking']->cancellation_fee);
        $this->assertSame('customer', $r['booking']->cancelled_by_role);
        $this->assertEquals(450.0, app(WalletService::class)->balance($s['customer']), 'prepaid: fee kept, rest refunded');
    }

    public function test_in_progress_cannot_be_cancelled_by_the_customer(): void
    {
        $s = $this->inProgress();

        $d = $this->decision($s['booking']);
        $this->assertFalse($d['allowed']);
        $this->assertSame('work_in_progress', $d['code']);

        $this->expectException(CancellationBlockedException::class);
        $this->cancel($s['booking']);
    }

    public function test_the_api_refuses_a_mid_work_cancel_and_the_booking_is_untouched(): void
    {
        $s = $this->inProgress();

        $this->actingAs($s['customer'], 'sanctum')
            ->postJson("/api/bookings/{$s['booking']->id}/cancel", ['reason' => 'nope'])
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'work_in_progress');

        $this->assertSame('in_progress', $s['booking']->fresh()->status);

        $this->actingAs($s['customer'], 'sanctum')
            ->getJson("/api/bookings/{$s['booking']->id}/cancel-quote")
            ->assertOk()->assertJsonPath('data.allowed', false);
    }

    public function test_provider_side_hold_is_free_to_cancel_with_a_full_refund(): void
    {
        $s = $this->inProgress();
        $s['booking']->update(['payment_status' => 'paid']);
        Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'provider_unresponsive', 'left');

        $d = $this->decision($s['booking']);
        $this->assertTrue($d['allowed']);
        $this->assertSame('provider_fault', $d['code']);

        $this->cancel($s['booking']);
        $this->assertEquals(500.0, app(WalletService::class)->balance($s['customer']));
    }

    public function test_customer_side_holds_other_than_spares_stay_locked(): void
    {
        $s = $this->inProgress();
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_customer_approval', 'extra work');

        $d = $this->decision($s['booking']);
        $this->assertFalse($d['allowed']);
        $this->assertSame('customer_hold', $d['code']);
    }

    public function test_spares_hold_is_locked_before_the_threshold_and_open_after_it(): void
    {
        $s = $this->inProgress();
        $this->backdate(5, fn () => $this->hold($s['booking']));

        $d = $this->decision($s['booking']);
        $this->assertFalse($d['allowed']);
        $this->assertSame('spares_locked', $d['code']);
        $this->assertNotNull($d['unlocks_at']);

        // Jump ahead: 11 days waiting in total.
        Carbon::setTestNow(now()->addDays(6));
        $d = $this->decision($s['booking']);
        $this->assertTrue($d['allowed']);
        $this->assertSame('spares_delay', $d['code']);
    }

    // ------------------------------------------------------------------ the clock

    public function test_re_hold_after_resume_does_not_reset_the_clock(): void
    {
        $s = $this->inProgress();

        $this->backdate(12, fn () => $this->hold($s['booking']));          // T-12d hold
        Carbon::setTestNow(now()->subDays(6));
        app(ResumeBookingAction::class)->execute($s['booking']->id);        // T-6d resume (6 days counted)
        Carbon::setTestNow(now()->subDays(-1));
        $this->hold($s['booking']->fresh());                                // T-5d re-hold
        Carbon::setTestNow();

        $clock = app(SparesDelayClock::class);
        $b = $s['booking']->fresh();
        $this->assertSame(11, $clock->countedDays($b), '6 days + 5 days, cumulative');
        $this->assertTrue($this->decision($b)['allowed'], 'the current hold alone is only 5 days; the clock must be cumulative');
    }

    public function test_days_on_a_customer_supplied_part_are_excluded(): void
    {
        $s = $this->inProgress();
        $this->backdate(15, fn () => $this->hold($s['booking'], ['sourced_by' => 'customer']));

        $b = $s['booking']->fresh();
        $this->assertSame(0, app(SparesDelayClock::class)->countedSeconds($b));
        $d = $this->decision($b);
        $this->assertFalse($d['allowed']);
        $this->assertSame('customer_supplied', $d['code']);
    }

    public function test_customer_caused_hold_days_never_count(): void
    {
        $s = $this->inProgress();
        $this->backdate(15, function () use ($s) {
            app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_customer_approval', 'extra work');
        });

        $this->assertSame(0, app(SparesDelayClock::class)->countedSeconds($s['booking']->fresh()));
    }

    public function test_an_expected_arrival_beyond_the_threshold_unlocks_cancel_immediately(): void
    {
        $s = $this->inProgress();
        $b = $this->hold($s['booking'], ['expected_at' => now()->addDays(11)->toDateString()]);

        $d = $this->decision($b);
        $this->assertTrue($d['allowed']);
        $this->assertSame('spares_expected_late', $d['code']);
    }

    public function test_changing_the_setting_changes_the_threshold_for_new_bookings_with_no_code_change(): void
    {
        $s = $this->inProgress();
        $this->backdate(5, fn () => $this->hold($s['booking']));
        $this->assertFalse($this->decision($s['booking'])['allowed'], 'default 10 days');

        // Bookings created AFTER the change pick the new value up (the old one is frozen — see the snapshot tests).
        Setting::set('cancellation.spares_delay_days', '4');
        $new = $this->inProgress();
        $this->backdate(5, fn () => $this->hold($new['booking']));
        $this->assertTrue($this->decision($new['booking'])['allowed'], '5 days waited >= the new 4-day limit');

        Setting::set('cancellation.spares_delay_days', '30');
        $later = $this->inProgress();
        $this->backdate(5, fn () => $this->hold($later['booking']));
        $this->assertFalse($this->decision($later['booking'])['allowed']);

        // A per-category override is just another settings row (this booking's category is new, so re-take its snapshot).
        $override = $this->inProgress();
        Setting::set("cancellation.spares_delay_days.category_{$override['category']->id}", '2');
        $override['booking']->update(['cancellation_policy_snapshot' => \App\Services\Cancellation\PolicySettings::snapshot($override['booking'])]);
        $this->backdate(5, fn () => $this->hold($override['booking']));
        $this->assertTrue($this->decision($override['booking'])['allowed']);
    }

    // ------------------------------------------------------------------ the charge

    public function test_charge_is_the_lesser_of_progress_value_and_the_cap_plus_evidenced_parts(): void
    {
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '50');
        Setting::set('cancellation.interim_min_labour', '50');
        $s = $this->inProgress();
        $calc = app(InterimChargeCalculator::class);

        $b = $this->hold($s['booking'], ['progress_percent' => 40]);   // 40% of 500 = 200 < cap 250
        $this->assertSame(200.0, $calc->calculate($b)['labour_charge']);

        $b->update(['interim_progress_percent' => 80]);                   // 400 > cap 250
        $this->assertSame(250.0, $calc->calculate($b->fresh())['labour_charge']);

        $b->update(['interim_progress_percent' => 5]);                    // 25 < minimum labour 50: floored
        $this->assertSame(50.0, $calc->calculate($b->fresh())['labour_charge']);

        // Parts: charged only with a bill/photo.
        $b->update(['interim_progress_percent' => 40, 'interim_parts_cost' => 120, 'interim_evidence' => null]);
        $this->assertSame(0.0, $calc->calculate($b->fresh())['parts_charge']);
        $this->assertSame(200.0, $calc->calculate($b->fresh())['total']);

        $b->update(['interim_evidence' => ['booking-evidence/1/bill.jpg']]);
        $this->assertSame(120.0, $calc->calculate($b->fresh())['parts_charge']);
        $this->assertSame(320.0, $calc->calculate($b->fresh())['total']);
    }

    // ------------------------------------------------------------------ minimum labour charge (NOT a visit charge)

    public function test_the_minimum_labour_floor_comes_from_its_own_setting_and_null_means_no_floor(): void
    {
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '149');
        $calc = app(InterimChargeCalculator::class);

        // Unset: no floor at all — 5% of 500 is just 25, even though a visit charge of 149 is configured.
        $s = $this->inProgress();
        $b = $this->hold($s['booking'], ['progress_percent' => 5]);
        $this->assertSame(25.0, $calc->calculate($b)['labour_charge'], 'null = no floor; the visit charge is never a floor');
        $this->assertFalse($calc->calculate($b)['min_labour_applied']);

        // Configured: the floor applies and is labelled as such.
        Setting::set('cancellation.interim_min_labour', '75');
        $s2 = $this->inProgress();
        $b2 = $this->hold($s2['booking'], ['progress_percent' => 5]);
        $r = $calc->calculate($b2);
        $this->assertSame(75.0, $r['labour_charge']);
        $this->assertTrue($r['min_labour_applied']);
        $this->assertSame(75.0, $r['min_labour']);

        // Progress above the floor is untouched by it.
        $b2->update(['interim_progress_percent' => 40]);
        $this->assertSame(200.0, $calc->calculate($b2->fresh())['labour_charge']);
        $this->assertFalse($calc->calculate($b2->fresh())['min_labour_applied']);
    }

    public function test_changing_the_visit_charge_never_changes_the_minimum_labour_charge(): void
    {
        Setting::set('cancellation.interim_min_labour', '75');
        $calc = app(InterimChargeCalculator::class);

        foreach (['0', '149', '199'] as $visit) {
            Setting::set('cancellation.visit_fee_value', $visit);
            $s = $this->inProgress();
            $b = $this->hold($s['booking'], ['progress_percent' => 5]);

            $this->assertSame(75.0, $calc->calculate($b)['labour_charge'], "visit charge {$visit}");
        }
    }

    public function test_the_minimum_labour_charge_follows_the_booking_snapshot(): void
    {
        Setting::set('cancellation.interim_min_labour', '75');
        $s = $this->inProgress();
        $b = $this->hold($s['booking'], ['progress_percent' => 5]);

        Setting::set('cancellation.interim_min_labour', '300'); // owner changes it later
        $this->assertSame(75.0, app(InterimChargeCalculator::class)->calculate($b->fresh())['labour_charge'], 'the existing booking keeps the floor it was made under');

        $new = $this->hold($this->inProgress()['booking'], ['progress_percent' => 5]);
        $this->assertSame(300.0, app(InterimChargeCalculator::class)->calculate($new)['labour_charge']);
    }

    public function test_customer_text_calls_it_a_minimum_labour_charge_and_never_a_visit_charge(): void
    {
        Setting::set('cancellation.visit_fee_value', '149');
        Setting::set('cancellation.interim_min_labour', '75');
        $s = $this->inProgress();
        $b = null;
        $this->backdate(11, function () use ($s, &$b) { // past the spares-delay limit so a quote exists
            $b = $this->hold($s['booking'], ['progress_percent' => 5]);
        });

        $line = collect(app(CancellationPolicy::class)->policyLines($b->fresh()))->first(fn ($l) => str_contains($l, 'minimum labour charge'));

        $this->assertNotNull($line);
        $this->assertStringContainsString('A minimum labour charge of ₹75 applies once any work has been done.', $line);
        $this->assertStringNotContainsString('minimum labour charge of ₹149', $line);

        $quote = app(CustomerCancelBookingAction::class)->quote($b->fresh());
        $this->assertTrue($quote['breakdown']['min_labour_applied'] ?? false);
        $this->assertArrayNotHasKey('display', $quote['breakdown'] ?? [], 'no "visit charge" label on a mid-work quote');
    }

    public function test_parts_declared_without_a_bill_are_rejected_at_hold_time(): void
    {
        $s = $this->inProgress();

        $this->expectException(\InvalidArgumentException::class);
        $this->hold($s['booking'], ['parts_fitted_cost' => 120, 'evidence' => []]);
    }

    public function test_missing_declaration_falls_back_to_the_visit_fee_only(): void
    {
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '75');
        $s = $this->inProgress();
        // An operator hold: no declaration at all.
        $this->backdate(11, fn () => app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares', 'operator hold'));

        $d = $this->decision($s['booking']);
        $this->assertTrue($d['allowed']);
        $this->assertSame(75.0, $d['charge']);
        $this->assertFalse($d['breakdown']['declared']);
        $this->assertSame(0.0, $d['breakdown']['parts_charge']);
    }

    public function test_a_re_hold_cannot_declare_less_than_before(): void
    {
        $s = $this->inProgress();
        $b = $this->hold($s['booking'], ['progress_percent' => 60]);
        app(ResumeBookingAction::class)->execute($b->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->hold($b->fresh(), ['progress_percent' => 30]);
    }

    // ------------------------------------------------------------------ provider fault paths

    public function test_spares_ready_but_not_resumed_for_48h_is_free_and_penalises_the_provider_once(): void
    {
        Notification::fake();
        Setting::set('notifications.channels', 'mail');
        $s = $this->inProgress();
        $this->backdate(3, function () use ($s) {
            $this->hold($s['booking']);
            app(MarkSparesAvailableAction::class)->execute($s['booking']->id);
        });

        $d = $this->decision($s['booking']);
        $this->assertTrue($d['allowed']);
        $this->assertSame('provider_delay', $d['code']);
        $this->assertSame(0.0, $d['charge']);

        $sweep = app(CancellationSweepService::class);
        $sweep->sparesNotices();
        $sweep->sparesNotices();

        $this->assertSame(90, $s['provider']->fresh()->reliability_score, 'penalised once, not per run');
        $this->assertDatabaseCount('provider_reliability_events', 1);
    }

    // ------------------------------------------------------------------ disputes

    public function test_a_dispute_blocks_cancellation_until_an_admin_resolves_it(): void
    {
        $s = $this->inProgress();
        $this->backdate(11, fn () => $this->hold($s['booking']));
        // Declared 11 days ago: the 48h dispute window is gone, so re-declare "now" via a fresh hold.
        app(ResumeBookingAction::class)->execute($s['booking']->id);
        $this->hold($s['booking']->fresh(), ['expected_at' => now()->addDays(11)->toDateString()]);

        app(DisputeInterimDeclarationAction::class)->execute($s['booking']->id, $s['customer']->id, 'He did not do 40%');
        $d = $this->decision($s['booking']);
        $this->assertFalse($d['allowed']);
        $this->assertSame('dispute_open', $d['code']);

        $admin = User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Admin', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active']);
        app(ResolveInterimDisputeAction::class)->execute($s['booking']->id, $admin, 'Photos show 20%', 20);

        $b = $s['booking']->fresh();
        $this->assertSame(20, $b->interim_progress_percent);
        $this->assertTrue($this->decision($b)['allowed']);
    }

    public function test_a_dispute_after_the_window_is_refused(): void
    {
        $s = $this->inProgress();
        $this->backdate(3, fn () => $this->hold($s['booking']));

        $this->expectException(\RuntimeException::class);
        app(DisputeInterimDeclarationAction::class)->execute($s['booking']->id, $s['customer']->id, 'too late');
    }

    // ------------------------------------------------------------------ settlement

    private function unlockedScenario(): array
    {
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '50');
        $s = $this->inProgress();
        $this->backdate(11, fn () => $this->hold($s['booking'], ['progress_percent' => 20]));   // 20% of 500 = 100
        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    public function test_quote_token_is_required_and_a_stale_one_re_quotes(): void
    {
        $s = $this->unlockedScenario();

        try {
            $this->cancel($s['booking']);
            $this->fail('expected a quote-changed exception');
        } catch (CancellationQuoteChangedException $e) {
            $this->assertSame(100.0, $e->quote['charge']);
            $this->assertNotEmpty($e->quote['token']);
        }

        $this->expectException(CancellationQuoteChangedException::class);
        $this->cancel($s['booking'], 'garbage.token');
    }

    public function test_unpaid_booking_pays_the_charge_from_the_wallet_then_cancels_and_the_provider_is_paid(): void
    {
        $s = $this->unlockedScenario();
        $this->seedWallet($s['customer'], 1000);

        $r = $this->cancel($s['booking'], $this->token($s['booking']));

        $this->assertSame('cancelled', $r['outcome']);
        $this->assertSame('cancelled', $s['booking']->fresh()->status);
        $this->assertEquals(100, $s['booking']->fresh()->cancellation_fee);
        $this->assertEquals(900.0, app(WalletService::class)->balance($s['customer']));
        // franchise platform_fee 5% + revenue share 10% => provider gets 85 of 100
        $this->assertEquals(85.0, app(WalletService::class)->balance($s['provider']->user));
        $this->assertDatabaseHas('commissions', ['booking_id' => $s['booking']->id]);
    }

    public function test_provider_payout_is_idempotent(): void
    {
        $s = $this->unlockedScenario();
        $this->seedWallet($s['customer'], 1000);
        $this->cancel($s['booking'], $this->token($s['booking']));

        $action = app(CustomerCancelBookingAction::class);
        $action->payProvider($s['booking']->fresh(), 100);
        $action->payProvider($s['booking']->fresh(), 100);
        app(CancellationSweepService::class)->payoutRetries();

        $this->assertEquals(85.0, app(WalletService::class)->balance($s['provider']->user));
        $this->assertDatabaseCount('commissions', 1);
    }

    public function test_unpaid_booking_with_no_wallet_gets_a_gateway_order_and_completes_on_capture(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createRawOrder')->once()->andReturn(['razorpay_order_id' => 'order_cancel_1', 'key_id' => 'rzp_test_key', 'amount' => 10000, 'currency' => 'INR']);
        $s = $this->unlockedScenario();

        $r = $this->cancel($s['booking'], $this->token($s['booking']));

        $this->assertSame('payment_required', $r['outcome']);
        $this->assertSame('on_hold', $s['booking']->fresh()->status, 'not cancelled until the charge is paid');
        $payment = Payment::where('purpose', 'cancellation_fee')->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertEquals(100, $payment->amount);

        // Double submit reuses the pending order (no second gateway order: createRawOrder is ->once()).
        $again = $this->cancel($s['booking'], $this->token($s['booking']));
        $this->assertSame('payment_required', $again['outcome']);
        $this->assertSame(1, BookingCancellationRequest::count());

        // Webhook capture completes it.
        $payment->update(['status' => 'captured', 'gateway_payment_id' => 'pay_1']);
        app(CustomerCancelBookingAction::class)->completeAfterChargePayment($payment->fresh());

        $this->assertSame('cancelled', $s['booking']->fresh()->status);
        $this->assertEquals(100, $s['booking']->fresh()->cancellation_fee);
        $this->assertSame('completed', BookingCancellationRequest::first()->status);
        $this->assertEquals(85.0, app(WalletService::class)->balance($s['provider']->user));
    }

    public function test_a_charge_paid_after_the_job_resumed_is_refunded_and_nothing_is_cancelled(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createRawOrder')->once()->andReturn(['razorpay_order_id' => 'order_cancel_2', 'key_id' => 'k', 'amount' => 10000, 'currency' => 'INR']);
        $gateway->shouldReceive('refund')->once()->with('pay_2', 100.0, Mockery::any())->andReturn([]);
        $s = $this->unlockedScenario();

        $this->cancel($s['booking'], $this->token($s['booking']));
        app(ResumeBookingAction::class)->execute($s['booking']->id);   // job continues before the customer pays

        $payment = Payment::where('purpose', 'cancellation_fee')->firstOrFail();
        $payment->update(['status' => 'captured', 'gateway_payment_id' => 'pay_2']);
        app(CustomerCancelBookingAction::class)->completeAfterChargePayment($payment->fresh());

        $this->assertSame('in_progress', $s['booking']->fresh()->status);
        $this->assertSame('superseded', BookingCancellationRequest::first()->status);
    }

    public function test_without_online_payment_the_request_goes_to_admin_and_can_be_waived_with_a_reason(): void
    {
        $s = $this->unlockedScenario();   // test env: gateway not configured, wallet empty

        $r = $this->cancel($s['booking'], $this->token($s['booking']));
        $this->assertSame('awaiting_admin', $r['outcome']);
        $this->assertSame('on_hold', $s['booking']->fresh()->status);

        $admin = User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Admin', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active']);

        try {
            app(WaiveCancellationChargeAction::class)->execute($r['request']->id, $admin, '  ');
            $this->fail('a reason is required');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        app(WaiveCancellationChargeAction::class)->execute($r['request']->id, $admin, 'Goodwill after dispute');

        $b = $s['booking']->fresh();
        $this->assertSame('cancelled', $b->status);
        $this->assertEquals(0, $b->cancellation_fee);
        $this->assertSame('waived', BookingCancellationRequest::first()->status);
        $this->assertTrue(\App\Models\ActivityLog::where('description', 'cancellation charge waived')->exists());
    }

    public function test_an_unpaid_charge_is_flagged_for_admin_after_seven_days(): void
    {
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('createRawOrder')->andReturn(['razorpay_order_id' => 'order_cancel_3', 'key_id' => 'k', 'amount' => 10000, 'currency' => 'INR']);
        $s = $this->unlockedScenario();
        $this->cancel($s['booking'], $this->token($s['booking']));

        $request = BookingCancellationRequest::firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $request->due_by->timestamp, 5);

        $this->assertSame(0, app(CancellationSweepService::class)->unpaidRequests());

        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame(1, app(CancellationSweepService::class)->unpaidRequests());
        $this->assertSame('awaiting_admin', $request->fresh()->status);
        $this->assertSame('on_hold', $s['booking']->fresh()->status, 'never left stuck or silently cancelled');
    }

    public function test_a_double_submit_of_the_cancel_is_idempotent(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['payment_status' => 'paid']);
        Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);

        $first = $this->cancel($s['booking']);
        $second = $this->cancel($s['booking']);

        $this->assertSame('cancelled', $first['outcome']);
        $this->assertFalse($first['already']);
        $this->assertSame('cancelled', $second['outcome']);
        $this->assertTrue($second['already']);
        $this->assertEquals(500.0, app(WalletService::class)->balance($s['customer']), 'refunded exactly once');
        $this->assertSame(1, $s['booking']->statusHistory()->where('status', 'cancelled')->count());
    }

    public function test_someone_elses_booking_is_not_found(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $other = $this->makeCustomer();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(CustomerCancelBookingAction::class)->execute($s['booking']->id, $other->id, 'x');
    }

    // ------------------------------------------------------------------ notices + timeouts

    public function test_delay_notices_go_to_both_sides_once(): void
    {
        Notification::fake();
        Setting::set('notifications.channels', 'mail');
        $s = $this->inProgress();
        $this->backdate(8, fn () => $this->hold($s['booking'], ['expected_at' => now()->addDays(3)->toDateString()]));   // day 8 of 10 => warning window

        $sweep = app(CancellationSweepService::class);
        $sweep->sparesNotices();
        $sweep->sparesNotices();

        $events = fn ($notifiable, string $class, string $event) => Notification::sent($notifiable, $class)
            ->filter(fn ($n) => str_ends_with($n->eventKey(), $event))->count();

        $this->assertSame(1, $events($s['customer'], BookingStatusNotification::class, 'spares_delay_warning'));
        $this->assertSame(1, $events($s['provider']->user, \App\Notifications\ProviderJobStatusNotification::class, 'spares_delay_warning'));

        // Unlock day + the expected date having passed
        Carbon::setTestNow(now()->addDays(5));
        $sweep->sparesNotices();
        $sweep->sparesNotices();
        $this->assertSame(1, $events($s['customer'], BookingStatusNotification::class, 'spares_cancel_unlocked'));
        $this->assertSame(1, $events($s['provider']->user, \App\Notifications\ProviderJobStatusNotification::class, 'spares_cancel_unlocked'));
        $this->assertSame(1, $events($s['customer'], BookingStatusNotification::class, 'spares_date_passed'));
        $this->assertSame(1, $events($s['provider']->user, \App\Notifications\ProviderJobStatusNotification::class, 'spares_date_passed'));
    }

    public function test_extra_work_unanswered_for_72_hours_is_declined_and_the_job_resumes(): void
    {
        Notification::fake();
        $s = $this->inProgress();
        app(ProposeExtraWorkAction::class)->execute($s['booking']->id, $s['provider'], 'Gas refill', 300);
        $this->assertSame('on_hold', $s['booking']->fresh()->status);

        Carbon::setTestNow(now()->addHours(71));
        $this->assertSame(0, app(CancellationSweepService::class)->extraWorkTimeouts());

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(1, app(CancellationSweepService::class)->extraWorkTimeouts());

        $b = $s['booking']->fresh();
        $this->assertSame('in_progress', $b->status, 'resumed at the original price');
        $this->assertDatabaseHas('booking_extra_items', ['booking_id' => $b->id, 'status' => 'rejected']);
    }

    // ------------------------------------------------------------------ bundles

    public function test_policy_text_states_the_configured_numbers(): void
    {
        Setting::set('cancellation.spares_delay_days', '12');
        $lines = implode(' ', app(CancellationPolicy::class)->policyLines());

        $this->assertStringContainsString('12 days', $lines);
        $this->assertStringContainsString('50%', $lines);
    }
}
