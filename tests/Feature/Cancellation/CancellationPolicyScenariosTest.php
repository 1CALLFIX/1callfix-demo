<?php

namespace Tests\Feature\Cancellation;

use App\Actions\CheckInArrivalAction;
use App\Actions\CompleteBookingAction;
use App\Actions\CustomerCancelBookingAction;
use App\Actions\LogCallAttemptAction;
use App\Actions\ProposeExtraWorkAction;
use App\Actions\ProviderCancelBookingAction;
use App\Actions\RespondToBookingQuoteAction;
use App\Actions\RespondToExtraWorkAction;
use App\Actions\SendBookingQuoteAction;
use App\Actions\StartBookingAction;
use App\Actions\WaiveCancellationChargeAction;
use App\Livewire\CancellationPolicy\Manage;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\Commission;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Cancellation\CancellationBlockedException;
use App\Services\Cancellation\CancellationPolicy;
use App\Services\Cancellation\PolicySettings;
use App\Services\Documents\CancellationDocumentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-CANCEL-POLICY-001 phase 2 — one test per cancellation scenario, arrival check-in, in-app quote, the policy
 * snapshot, the Prime waiver, the admin screen + audit log, the cancellation invoice / credit note, and the
 * "paid exactly once" guarantees. Every fee in here is set by the test itself: nothing relies on an invented default.
 */
class CancellationPolicyScenariosTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** Admin-configured values, set BEFORE the booking is made (they are frozen onto it at creation). */
    private function cfg(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::set('cancellation.'.$key, (string) $value);
        }
    }

    private function scenario(string $status, bool $prepaid = true): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => $status]);

        if ($prepaid) {
            $s['booking']->update(['payment_status' => 'paid']);
            Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);
        }
        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    private function arrive(array $s, float $lat = 1.0, float $lng = 1.0): Booking
    {
        return app(CheckInArrivalAction::class)->execute($s['booking']->id, $s['provider'], $lat, $lng);
    }

    private function quote(Booking $booking): array
    {
        return app(CustomerCancelBookingAction::class)->quote($booking->fresh());
    }

    private function customerCancel(array $s): array
    {
        $action = app(CustomerCancelBookingAction::class);
        $token = $action->quote($s['booking']->fresh())['token'];

        return $action->execute($s['booking']->id, $s['customer']->id, 'Changed my mind', $token);
    }

    private function providerCancel(array $s, string $reason): array
    {
        return app(ProviderCancelBookingAction::class)->execute($s['booking']->id, $s['provider'], $reason);
    }

    private function providerWallet(array $s): float
    {
        return app(WalletService::class)->balance($s['provider']->user);
    }

    private function reliability(array $s): int
    {
        return (int) $s['provider']->fresh()->reliability_score;
    }

    // ================================================================== STEP 2 — scenarios

    public function test_scenario_1_booked_with_no_provider_cancels_free(): void
    {
        $this->cfg(['assigned_fee' => 99, 'en_route_fee' => 99]);

        foreach (['pending', 'searching_provider'] as $status) {
            $s = $this->makeBookingScenario($status);
            $d = app(CancellationPolicy::class)->evaluate($s['booking']->fresh());

            $this->assertTrue($d['allowed'], $status);
            $this->assertSame(0.0, $d['charge'], $status);
            $this->assertTrue($d['free']);
        }
    }

    public function test_scenario_2_assigned_not_travelling_cancel_charges_zero_and_credits_nothing(): void
    {
        // assigned_fee left UNSET: zero, no fee — and the old time-based fee must not apply here.
        $this->cfg(['fee_type' => 'flat', 'fee_value' => 75, 'free_minutes' => 0]);
        $s = $this->scenario('assigned');
        $s['booking']->forceFill(['created_at' => now()->subHours(3)])->save();

        $result = $this->customerCancel($s);

        $this->assertSame('cancelled', $result['outcome']);
        $this->assertEquals(0, $result['booking']->cancellation_fee);
        $this->assertEquals(500.0, app(WalletService::class)->balance($s['customer']), 'prepaid amount refunded in full');
        $this->assertSame(0, Commission::where('booking_id', $s['booking']->id)->count(), 'provider is credited nothing');
        $this->assertEquals(0.0, $this->providerWallet($s));
    }

    public function test_scenario_2_an_explicit_assigned_fee_is_kept_by_the_platform_and_the_provider_receives_nothing(): void
    {
        $this->cfg(['assigned_fee' => 40]);
        $s = $this->scenario('assigned');

        $result = $this->customerCancel($s);

        $this->assertEquals(40, $result['booking']->cancellation_fee);
        $this->assertEquals(460.0, app(WalletService::class)->balance($s['customer']));
        $this->assertSame(0, Commission::where('booking_id', $s['booking']->id)->count());
        $this->assertEquals(0.0, $this->providerWallet($s), 'the assigned-stage fee never reaches the professional');
    }

    public function test_scenario_3_en_route_cancel_charges_the_en_route_fee_and_pays_the_provider_fee_minus_commission(): void
    {
        $this->cfg(['en_route_fee' => 80]);
        $s = $this->scenario('provider_en_route');

        $this->assertSame('en_route', $this->quote($s['booking'])['code']);
        $result = $this->customerCancel($s);

        $this->assertEquals(80, $result['booking']->cancellation_fee);
        $this->assertEquals(420.0, app(WalletService::class)->balance($s['customer']));

        $commission = Commission::where('booking_id', $s['booking']->id)->firstOrFail();
        $this->assertEqualsWithDelta(80.0, (float) $commission->provider_commission + (float) $commission->platform_commission + (float) $commission->franchise_commission, 0.01);
        $this->assertGreaterThan(0.0, (float) $commission->platform_commission, 'commission is taken');
        $this->assertEqualsWithDelta((float) $commission->provider_commission, $this->providerWallet($s), 0.01, 'provider gets fee minus commission');
    }

    public function test_scenario_4_arrived_then_the_customer_refuses_charges_the_visit_fee(): void
    {
        $this->cfg(['visit_fee_type' => 'flat', 'visit_fee_value' => 149, 'arrival_radius_meters' => 150]);
        $s = $this->scenario('provider_en_route');
        $this->arrive($s);

        $q = $this->quote($s['booking']);
        $this->assertSame('visit_charge', $q['code']);
        $this->assertSame(149.0, $q['charge']);

        $result = $this->customerCancel($s);
        $this->assertEquals(149, $result['booking']->cancellation_fee);
        $this->assertEquals(351.0, app(WalletService::class)->balance($s['customer']));

        $commission = Commission::where('booking_id', $s['booking']->id)->firstOrFail();
        $this->assertEqualsWithDelta(149.0, (float) $commission->provider_commission + (float) $commission->platform_commission + (float) $commission->franchise_commission, 0.01);
        $this->assertEqualsWithDelta((float) $commission->provider_commission, $this->providerWallet($s), 0.01);
    }

    public function test_scenario_5_customer_not_home_is_blocked_before_the_wait_and_the_call_attempts_then_allowed(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'no_show_wait_minutes' => 10, 'no_show_call_attempts' => 2]);
        $s = $this->scenario('provider_en_route');
        $this->arrive($s);

        $blocked = function (string $code) use ($s) {
            try {
                $this->providerCancel($s, 'customer_unreachable');
                $this->fail('should have been blocked: '.$code);
            } catch (CancellationBlockedException $e) {
                $this->assertSame($code, $e->decision['code']);
            }
        };

        $blocked('wait_not_over');

        Carbon::setTestNow(now()->addMinutes(11));
        $blocked('calls_missing');

        app(LogCallAttemptAction::class)->execute($s['booking']->id, $s['provider']);
        $blocked('calls_missing'); // 1 of 2
        $this->assertSame(2, app(LogCallAttemptAction::class)->execute($s['booking']->id, $s['provider']));

        $result = $this->providerCancel($s, 'customer_unreachable');

        $this->assertSame('cancelled', $result['booking']->status);
        $this->assertSame('provider', $result['booking']->cancelled_by_role);
        $this->assertSame(149.0, $result['charge']);
        $this->assertEquals(351.0, app(WalletService::class)->balance($s['customer']), 'fee kept from the prepaid amount');
        $this->assertSame(1, Commission::where('booking_id', $s['booking']->id)->count());
    }

    public function test_scenario_5_is_unavailable_while_the_wait_and_attempts_are_not_configured(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150]);
        $s = $this->scenario('provider_en_route');
        $this->arrive($s);

        $this->expectException(CancellationBlockedException::class);
        $this->providerCancel($s, 'customer_unreachable');
    }

    public function test_scenario_6_quote_rejected_cancel_needs_an_in_app_quote_the_customer_did_not_accept(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150]);
        $s = $this->scenario('provider_en_route');
        $this->arrive($s);

        try {
            $this->providerCancel($s, 'quote_rejected');
            $this->fail('no in-app quote on record');
        } catch (CancellationBlockedException $e) {
            $this->assertSame('no_quote', $e->decision['code']);
        }

        $quote = app(SendBookingQuoteAction::class)->execute($s['booking']->id, $s['provider'], 900);

        try {
            $this->providerCancel($s, 'quote_rejected');
            $this->fail('the customer has not answered yet');
        } catch (CancellationBlockedException $e) {
            $this->assertSame('quote_pending', $e->decision['code']);
        }

        app(RespondToBookingQuoteAction::class)->execute($quote->id, $s['customer']->id, false);
        $result = $this->providerCancel($s, 'quote_rejected');

        $this->assertSame(149.0, $result['charge']);
        $this->assertSame('cancelled', $result['booking']->status);
    }

    public function test_scenario_6_an_accepted_quote_blocks_the_cancel_and_an_unanswered_one_counts_after_the_configured_time(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'quote_response_minutes' => 30]);
        $s = $this->scenario('provider_en_route');
        $this->arrive($s);
        $quote = app(SendBookingQuoteAction::class)->execute($s['booking']->id, $s['provider'], 900);

        Carbon::setTestNow(now()->addMinutes(31));
        $result = $this->providerCancel($s, 'quote_rejected');
        $this->assertSame(149.0, $result['charge'], 'unanswered past quote_response_minutes counts as not accepted');

        // and the accepted case, on a fresh booking
        Carbon::setTestNow();
        $t = $this->scenario('provider_en_route');
        $this->arrive($t);
        $q2 = app(SendBookingQuoteAction::class)->execute($t['booking']->id, $t['provider'], 900);
        app(RespondToBookingQuoteAction::class)->execute($q2->id, $t['customer']->id, true);

        try {
            $this->providerCancel($t, 'quote_rejected');
            $this->fail('quote was accepted');
        } catch (CancellationBlockedException $e) {
            $this->assertSame('quote_accepted', $e->decision['code']);
        }
        unset($quote);
    }

    public function test_scenario_7_declining_extra_work_lets_the_job_continue_at_the_original_price(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);

        $item = app(ProposeExtraWorkAction::class)->execute($s['booking']->id, $s['provider'], 'Gas refill', 300);
        $this->assertSame('on_hold', $s['booking']->fresh()->status);

        app(RespondToExtraWorkAction::class)->execute($item->id, $s['customer']->id, false);

        $b = $s['booking']->fresh();
        $this->assertSame('in_progress', $b->status);
        $this->assertEquals(500, $b->price_quoted, 'original price');
        $this->assertDatabaseHas('booking_extra_items', ['id' => $item->id, 'status' => 'rejected']);
    }

    public function test_scenario_8_provider_late_beyond_the_configured_minutes_lets_the_customer_cancel_free_and_drops_reliability(): void
    {
        $this->cfg(['en_route_fee' => 80, 'provider_late_minutes' => 30]);
        $s = $this->scenario('provider_en_route');
        $s['booking']->update(['scheduled_at' => now()->subMinutes(45)]);

        $this->assertSame('provider_late', $this->quote($s['booking'])['code']);
        $before = $this->reliability($s);

        $result = $this->customerCancel($s);

        $this->assertEquals(0, $result['booking']->cancellation_fee);
        $this->assertEquals(500.0, app(WalletService::class)->balance($s['customer']));
        $this->assertSame($before - 10, $this->reliability($s), 'reliability drops by the configured points (10 by default)');
        $this->assertSame(0, Commission::where('booking_id', $s['booking']->id)->count());
    }

    public function test_scenario_8_not_late_yet_still_pays_the_en_route_fee(): void
    {
        $this->cfg(['en_route_fee' => 80, 'provider_late_minutes' => 30]);
        $s = $this->scenario('provider_en_route');
        $s['booking']->update(['scheduled_at' => now()->subMinutes(10)]);

        $this->assertSame('en_route', $this->quote($s['booking'])['code']);
    }

    public function test_scenario_9_provider_cancels_before_arrival_for_own_reasons_no_charge_and_reliability_drops(): void
    {
        $this->cfg(['en_route_fee' => 80]);
        $s = $this->scenario('provider_en_route');
        $before = $this->reliability($s);

        $result = $this->providerCancel($s, 'own_reason');

        $this->assertSame(0.0, $result['charge']);
        $this->assertSame('cancelled', $result['booking']->status);
        $this->assertEquals(500.0, app(WalletService::class)->balance($s['customer']), 'full refund');
        $this->assertSame($before - 10, $this->reliability($s));
        $this->assertSame(0, Commission::where('booking_id', $s['booking']->id)->count());
    }

    public function test_scenario_9_is_not_available_after_arrival(): void
    {
        $this->cfg(['arrival_radius_meters' => 150]);
        $s = $this->scenario('provider_en_route');
        $this->arrive($s);

        $this->expectException(CancellationBlockedException::class);
        $this->providerCancel($s, 'own_reason');
    }

    // ================================================================== STEP 3 — arrival check-in

    public function test_arrival_is_stored_with_position_and_time_when_inside_the_radius(): void
    {
        $this->cfg(['arrival_radius_meters' => 150]);
        $s = $this->scenario('provider_en_route');
        Carbon::setTestNow('2026-10-02 10:00:00');

        $b = $this->arrive($s, 1.0005, 1.0); // ~55 m

        $this->assertEquals(1.0005, (float) $b->arrival_lat);
        $this->assertEquals(1.0, (float) $b->arrival_lng);
        $this->assertSame('2026-10-02 10:00:00', $b->arrival_verified_at->format('Y-m-d H:i:s'));
        $this->assertGreaterThan(0, $b->arrival_distance_m);
        $this->assertLessThanOrEqual(150, $b->arrival_distance_m);
    }

    public function test_arrival_outside_the_radius_is_rejected_and_stores_nothing(): void
    {
        $this->cfg(['arrival_radius_meters' => 150]);
        $s = $this->scenario('provider_en_route');

        try {
            $this->arrive($s, 1.02, 1.0); // ~2.2 km away
            $this->fail('should be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Move within 150 m', $e->getMessage());
        }

        $b = $s['booking']->fresh();
        $this->assertNull($b->arrival_verified_at);
        $this->assertNull($b->arrival_lat);
    }

    public function test_arrival_cannot_be_verified_while_the_radius_is_not_configured(): void
    {
        $s = $this->scenario('provider_en_route');

        $this->expectExceptionMessage('not set up yet');
        $this->arrive($s);
    }

    public function test_no_arrival_check_in_means_the_visit_fee_cannot_be_charged(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'en_route_fee' => 0]);
        $s = $this->scenario('provider_en_route');

        // customer side: no verified arrival → the visit charge code never applies
        $this->assertNotSame('visit_charge', $this->quote($s['booking'])['code']);
        $this->assertSame(0.0, $this->quote($s['booking'])['charge']);

        // provider side: quote-rejected / no-show both refuse without a verified arrival
        foreach (['quote_rejected', 'customer_unreachable'] as $reason) {
            try {
                $this->providerCancel($s, $reason);
                $this->fail($reason);
            } catch (CancellationBlockedException $e) {
                $this->assertSame('no_arrival', $e->decision['code'], $reason);
            }
        }
    }

    // ================================================================== STEP 4 — fee handling

    public function test_the_visit_fee_is_adjusted_into_the_final_bill_when_the_work_goes_ahead(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150]);
        $s = $this->scenario('provider_en_route');
        $this->arrive($s);
        $quote = app(SendBookingQuoteAction::class)->execute($s['booking']->id, $s['provider'], 500);
        app(RespondToBookingQuoteAction::class)->execute($quote->id, $s['customer']->id, true);

        app(StartBookingAction::class)->execute($s['booking']->id, '1234');
        app(CompleteBookingAction::class)->execute($s['booking']->id, $s['provider'], '5678');

        $b = $s['booking']->fresh();
        $this->assertSame('completed', $b->status);
        $this->assertEquals(500, $b->price_quoted, 'the final bill is the job price — the visit fee is inside it, not on top');
        $this->assertNull($b->cancellation_fee);
        $this->assertSame(0, BookingCancellationRequest::where('booking_id', $b->id)->count());
        $this->assertSame(0, Payment::where('booking_id', $b->id)->where('purpose', 'cancellation_fee')->count(), 'no separate visit-fee charge');
        $this->assertStringContainsString('adjusted in your final bill', (string) app(CancellationPolicy::class)->visitChargeText($b));
    }

    public function test_prime_waiver_toggle_on_waives_the_en_route_and_visit_charges_and_off_does_not(): void
    {
        $this->cfg(['en_route_fee' => 80, 'visit_fee_value' => 149, 'arrival_radius_meters' => 150]);
        $plan = Plan::create([
            'name' => 'Prime Test', 'slug' => 'prime-test-'.Str::random(6), 'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'annual', 'price' => 1999, 'stacking_strategy' => 'exclusive', 'is_active' => true,
            'waives_cancellation_visit_charges' => true,
        ]);

        $s = $this->scenario('provider_en_route');
        Subscription::create(['subscribable_type' => User::class, 'subscribable_id' => $s['customer']->id, 'plan_id' => $plan->id, 'status' => 'active']);

        // ON: en route and visit charges both zero
        $this->assertSame(0.0, $this->quote($s['booking'])['charge']);
        $this->arrive($s);
        $this->assertSame(0.0, $this->quote($s['booking'])['charge']);

        // OFF: the same customer, same plan, toggle flipped → charged
        $plan->update(['waives_cancellation_visit_charges' => false]);
        $this->assertSame(149.0, $this->quote($s['booking'])['charge']);
    }

    public function test_a_charge_the_professional_raised_on_an_unpaid_booking_is_paid_by_wallet_and_the_provider_is_credited_exactly_once(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'no_show_wait_minutes' => 0, 'no_show_call_attempts' => 1]);
        $s = $this->scenario('provider_en_route', prepaid: false);
        $this->arrive($s);
        app(LogCallAttemptAction::class)->execute($s['booking']->id, $s['provider']);

        $result = $this->providerCancel($s, 'customer_unreachable');
        $request = $result['request'];

        $this->assertNotNull($request);
        $this->assertSame('awaiting_payment', $request->status);
        $this->assertEquals(149, $request->total_charge);
        $this->assertSame(0, Commission::where('booking_id', $s['booking']->id)->count(), 'nothing paid out before the customer pays');

        app(WalletService::class)->credit($s['customer'], 500, 'seed', 'seed:'.$s['customer']->id);
        $action = app(CustomerCancelBookingAction::class);
        $action->payOutstandingCharge($request->id, $s['customer']->id);
        $again = $action->payOutstandingCharge($request->id, $s['customer']->id);

        $this->assertTrue($again['already']);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertEquals(351.0, app(WalletService::class)->balance($s['customer']), 'charged once');
        $this->assertSame(1, Commission::where('booking_id', $s['booking']->id)->count());
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$s['booking']->id}:interim-payout")->count(), 'provider credited once');

        // and the sweep's retry can never pay a second time
        $action->payProvider($s['booking']->fresh(), 149.0);
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$s['booking']->id}:interim-payout")->count());
    }

    public function test_a_replayed_wallet_debit_after_a_crash_takes_the_money_only_once(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'no_show_wait_minutes' => 0, 'no_show_call_attempts' => 1]);
        $s = $this->scenario('provider_en_route', prepaid: false);
        $this->arrive($s);
        app(LogCallAttemptAction::class)->execute($s['booking']->id, $s['provider']);
        $request = $this->providerCancel($s, 'customer_unreachable')['request'];

        app(WalletService::class)->credit($s['customer'], 500, 'seed', 'seed:'.$s['customer']->id);
        // the debit happened, then the process died before the request was closed
        app(WalletService::class)->debit($s['customer'], 149, 'Cancellation charge', "booking:{$s['booking']->id}:cancel-charge:{$request->id}");
        $this->assertSame('awaiting_payment', $request->fresh()->status);

        app(CustomerCancelBookingAction::class)->payOutstandingCharge($request->id, $s['customer']->id);

        $this->assertSame('completed', $request->fresh()->status);
        $this->assertEquals(351.0, app(WalletService::class)->balance($s['customer']), 'debited once, not twice');
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$s['booking']->id}:cancel-charge:{$request->id}")->count());
        $this->assertSame(1, Commission::where('booking_id', $s['booking']->id)->count());
    }

    public function test_an_admin_can_waive_a_professional_raised_charge_with_a_reason_and_it_is_logged(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'no_show_wait_minutes' => 0, 'no_show_call_attempts' => 1]);
        $s = $this->scenario('provider_en_route', prepaid: false);
        $this->arrive($s);
        app(LogCallAttemptAction::class)->execute($s['booking']->id, $s['provider']);
        $request = $this->providerCancel($s, 'customer_unreachable')['request'];
        $admin = $this->makeCustomer();
        $admin->forceFill(['role' => 'super_admin'])->save();

        try {
            app(WaiveCancellationChargeAction::class)->execute($request->id, $admin, '  ');
            $this->fail('a reason is required');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        app(WaiveCancellationChargeAction::class)->execute($request->id, $admin, 'Customer was in hospital');

        $this->assertSame('waived', $request->fresh()->status);
        $this->assertEquals(0, $s['booking']->fresh()->cancellation_fee);
        $this->assertSame(0, Commission::where('booking_id', $s['booking']->id)->count());
        $this->assertDatabaseHas('activity_log', ['causer_id' => $admin->id, 'description' => 'cancellation charge waived']);
    }

    // ================================================================== STEP 5 — policy snapshot

    public function test_changing_a_setting_does_not_change_an_existing_bookings_fee_but_does_change_new_ones(): void
    {
        $this->cfg(['en_route_fee' => 80, 'visit_fee_value' => 149, 'arrival_radius_meters' => 150]);
        $old = $this->scenario('provider_en_route');
        $this->arrive($old);

        $this->cfg(['en_route_fee' => 500, 'visit_fee_value' => 400, 'arrival_radius_meters' => 5]);

        $this->assertSame(149.0, $this->quote($old['booking'])['charge'], 'the existing booking keeps the visit fee in force when it was made');
        $this->assertSame('80', (string) $old['booking']->fresh()->cancellation_policy_snapshot['cancellation.en_route_fee']);

        $new = $this->scenario('provider_en_route');
        $this->assertSame(500.0, $this->quote($new['booking'])['charge'], 'a booking made after the change uses the new value');
    }

    public function test_null_means_not_configured_and_zero_means_explicitly_zero(): void
    {
        $s = $this->scenario('assigned');
        $snap = $s['booking']->cancellation_policy_snapshot;
        $this->assertNull($snap['cancellation.assigned_fee'], 'unset is stored as null');
        $this->assertSame(0.0, (float) PolicySettings::get($s['booking'], 'cancellation.assigned_fee'), 'null falls back to the default (0)');
        $this->assertNull(PolicySettings::get($s['booking'], 'cancellation.arrival_radius_meters'), 'no default for the provider-side rules');

        $this->cfg(['assigned_fee' => 0, 'spares_delay_days' => 7]);
        $t = $this->scenario('assigned');
        $this->assertSame('0', $t['booking']->cancellation_policy_snapshot['cancellation.assigned_fee'], 'an explicit 0 is kept as 0');
        $this->assertSame(7, PolicySettings::get($t['booking'], 'cancellation.spares_delay_days'));
    }

    // ================================================================== STEP 6 — admin screen + audit

    private function superAdmin(): User
    {
        $u = $this->makeCustomer();
        $u->forceFill(['role' => 'super_admin'])->save();

        return $u;
    }

    public function test_the_screen_renders_as_a_full_page_inside_the_admin_layout_on_every_tab(): void
    {
        // A Livewire::test() renders the component alone; only a real GET proves the layout exists (a missing layout is a 500).
        $admin = $this->superAdmin();

        foreach (['', '?tab=settings', '?tab=categories', '?tab=operations', '?tab=audit'] as $query) {
            $this->actingAs($admin)->get('/admin/cancellation-policy'.$query)
                ->assertOk()
                ->assertSee('Cancellation Policy')
                ->assertSee('1CallFix', false);
        }

        $this->actingAs($this->makeCustomer())->get('/admin/cancellation-policy')->assertStatus(403);
    }

    public function test_every_setting_change_is_audit_logged_with_admin_key_old_new_and_time(): void
    {
        $admin = $this->superAdmin();
        $component = Livewire::actingAs($admin)->test(Manage::class);

        $component->set('inputs.'.Manage::field('cancellation.en_route_fee'), '80')->call('save')->assertHasNoErrors();
        $component->set('inputs.'.Manage::field('cancellation.en_route_fee'), '95')->call('save')->assertHasNoErrors();
        $component->call('save'); // nothing changed: nothing logged

        $this->assertSame('95', Setting::get('cancellation.en_route_fee'));

        $logs = ActivityLog::where('subject_type', 'setting')->where('properties->key', 'cancellation.en_route_fee')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($admin->id, $logs[0]->causer_id);
        $this->assertNull($logs[0]->properties['old']);
        $this->assertSame('80', $logs[0]->properties['new']);
        $this->assertSame('80', $logs[1]->properties['old']);
        $this->assertSame('95', $logs[1]->properties['new']);
        $this->assertNotNull($logs[1]->created_at);
    }

    public function test_invalid_setting_values_are_rejected_and_nothing_is_saved(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('inputs.'.Manage::field('cancellation.assigned_fee'), '-5')
            ->set('inputs.'.Manage::field('cancellation.interim_cap_percent'), '150')
            ->set('inputs.'.Manage::field('cancellation.visit_fee_type'), 'percent')
            ->set('inputs.'.Manage::field('cancellation.visit_fee_value'), '120')
            ->set('inputs.'.Manage::field('cancellation.spares_delay_days'), '2.5')
            ->set('inputs.'.Manage::field('cancellation.arrival_radius_meters'), 'abc')
            ->set('inputs.'.Manage::field('cancellation.en_route_fee'), '60') // valid, but must NOT be saved alongside invalid ones
            ->call('save')
            ->assertHasErrors([
                'inputs.'.Manage::field('cancellation.assigned_fee'),
                'inputs.'.Manage::field('cancellation.interim_cap_percent'),
                'inputs.'.Manage::field('cancellation.visit_fee_value'),
                'inputs.'.Manage::field('cancellation.spares_delay_days'),
                'inputs.'.Manage::field('cancellation.arrival_radius_meters'),
            ]);

        $this->assertNull(Setting::get('cancellation.en_route_fee'));
        $this->assertSame(0, ActivityLog::where('subject_type', 'setting')->count());

        // the validator itself
        $this->assertNotNull(PolicySettings::validate('cancellation.interim_cap_percent', '101'));
        $this->assertNotNull(PolicySettings::validate('cancellation.interim_cap_percent', '-1'));
        $this->assertNull(PolicySettings::validate('cancellation.interim_cap_percent', '0'));
        $this->assertNull(PolicySettings::validate('cancellation.interim_cap_percent', ''), 'blank clears the key');
    }

    public function test_the_screen_is_super_admin_only_and_lists_every_registry_key(): void
    {
        $this->actingAs($this->makeCustomer());
        Livewire::test(Manage::class)->assertForbidden();

        $admin = $this->superAdmin();
        $html = Livewire::actingAs($admin)->test(Manage::class)->html();

        foreach (array_keys(PolicySettings::REGISTRY) as $key) {
            $this->assertStringContainsString($key, $html, "{$key} has a field on the admin screen");
        }
    }

    public function test_category_overrides_can_be_added_and_removed_and_are_audited(): void
    {
        $admin = $this->superAdmin();
        $s = $this->makeAssignedBookingScenario();
        $key = PolicySettings::CATEGORY_OVERRIDE_PREFIX.$s['category']->id;

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('setTab', 'categories')
            ->set('overrideCategoryId', $s['category']->id)->set('overrideDays', '4')->call('addOverride')->assertHasNoErrors();
        $this->assertSame('4', Setting::get($key));

        $c->set('overrideCategoryId', $s['category']->id)->set('overrideDays', '0')->call('addOverride')->assertHasErrors('overrideDays');

        $c->call('removeOverride', $s['category']->id);
        $this->assertNull(Setting::get($key));
        $this->assertSame(2, ActivityLog::where('subject_type', 'setting')->where('properties->key', $key)->count());
    }

    public function test_the_live_preview_shows_the_visit_charge_sentence_from_the_typed_value(): void
    {
        $html = Livewire::actingAs($this->superAdmin())->test(Manage::class)
            ->set('inputs.'.Manage::field('cancellation.visit_fee_value'), '149')
            ->html();

        $this->assertStringContainsString('Visit and inspection charge ₹149, adjusted in your final bill if you go ahead with the work.', $html);
    }

    public function test_the_operations_tab_lists_held_pending_and_disputed_bookings(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'no_show_wait_minutes' => 0, 'no_show_call_attempts' => 1]);
        $s = $this->scenario('provider_en_route', prepaid: false);
        $this->arrive($s);
        app(LogCallAttemptAction::class)->execute($s['booking']->id, $s['provider']);
        $this->providerCancel($s, 'customer_unreachable');

        $html = Livewire::actingAs($this->superAdmin())->test(Manage::class)->call('setTab', 'operations')->html();

        $this->assertStringContainsString($s['booking']->code, $html);
        $this->assertStringContainsString('Cancellation charges pending payment (1)', $html);
        $this->assertStringContainsString('Held for spares', $html);
        $this->assertStringContainsString('Disputes awaiting resolution', $html);
    }

    // ================================================================== STEP 7 — invoice and credit note

    public function test_cancellation_invoice_and_credit_note_are_generated_numbered_once_and_downloadable(): void
    {
        $this->cfg(['en_route_fee' => 80]);
        $s = $this->scenario('provider_en_route');
        $this->customerCancel($s);
        $booking = $s['booking']->fresh();
        $docs = app(CancellationDocumentService::class);

        $invoice = $docs->invoice($booking);
        $this->assertStringStartsWith('INV/', $invoice['number']);
        $this->assertSame('Cancellation Invoice', $invoice['title']);
        $this->assertEquals(80.0, $invoice['total']);
        $this->assertCount(1, $invoice['lines'], 'one line, no tax breakdown (as every other document in the system)');

        $credit = $docs->creditNote($booking);
        $this->assertStringStartsWith('CRN/', $credit['number']);
        $this->assertEquals(420.0, $credit['total'], 'the refunded part of the prepaid payment');

        // idempotent numbering: asking again returns the same numbers, one row each
        $this->assertSame($invoice['number'], $docs->invoice($booking)['number']);
        $this->assertSame($credit['number'], $docs->creditNote($booking)['number']);
        $this->assertSame(1, GeneratedDocument::where('documentable_type', Booking::class)->where('documentable_id', $booking->id)->where('type', 'invoice')->count());
        $this->assertSame(1, GeneratedDocument::where('documentable_type', Booking::class)->where('documentable_id', $booking->id)->where('type', 'credit_note')->count());

        // downloadable by the customer, 404 for anyone else
        $this->actingAs($s['customer'])->get(route('customer.orders.cancellation-invoice', $booking))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($s['customer'])->get(route('customer.orders.credit-note', $booking))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->makeCustomer())->get(route('customer.orders.cancellation-invoice', $booking))->assertNotFound();

        // and by an admin
        $this->actingAs($this->superAdmin())->get(route('admin.documents.bookings.cancellation', [$booking->id, 'invoice']))->assertOk();
        $this->actingAs($this->superAdmin())->get(route('admin.documents.bookings.cancellation', [$booking->id, 'credit-note']))->assertOk();
    }

    public function test_no_invoice_exists_until_a_charge_has_actually_been_collected(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'arrival_radius_meters' => 150, 'no_show_wait_minutes' => 0, 'no_show_call_attempts' => 1]);
        $s = $this->scenario('provider_en_route', prepaid: false);
        $this->arrive($s);
        app(LogCallAttemptAction::class)->execute($s['booking']->id, $s['provider']);
        $request = $this->providerCancel($s, 'customer_unreachable')['request'];
        $docs = app(CancellationDocumentService::class);

        $this->assertNull($docs->invoice($s['booking']->fresh()), 'charged but not yet collected');
        $this->actingAs($s['customer'])->get(route('customer.orders.cancellation-invoice', $s['booking']))->assertNotFound();

        app(WalletService::class)->credit($s['customer'], 500, 'seed', 'seed:'.$s['customer']->id);
        app(CustomerCancelBookingAction::class)->payOutstandingCharge($request->id, $s['customer']->id);

        $this->assertNotNull($docs->invoice($s['booking']->fresh()));
        $this->assertNull($docs->creditNote($s['booking']->fresh()), 'nothing was prepaid, so nothing to credit');
    }

    // ================================================================== idempotency

    public function test_double_cancel_does_nothing_the_second_time(): void
    {
        $this->cfg(['en_route_fee' => 80]);
        $s = $this->scenario('provider_en_route');
        $action = app(CustomerCancelBookingAction::class);
        $token = $action->quote($s['booking']->fresh())['token'];

        $first = $action->execute($s['booking']->id, $s['customer']->id, 'x', $token);
        $second = $action->execute($s['booking']->id, $s['customer']->id, 'x', $token);

        $this->assertFalse($first['already']);
        $this->assertTrue($second['already']);
        $this->assertEquals(420.0, app(WalletService::class)->balance($s['customer']), 'refunded once');
        $this->assertSame(1, $s['booking']->statusHistory()->where('status', 'cancelled')->count());
        $this->assertSame(1, Commission::where('booking_id', $s['booking']->id)->count());
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$s['booking']->id}:interim-payout")->count(), 'provider credited exactly once');

        // provider-initiated: same guarantee
        $this->cfg(['arrival_radius_meters' => 150]);
        $t = $this->scenario('assigned');
        $a = $this->providerCancel($t, 'own_reason');
        $b = $this->providerCancel($t, 'own_reason');
        $this->assertFalse($a['already']);
        $this->assertTrue($b['already']);
        $this->assertSame(90, $this->reliability($t), 'penalised once');
    }
}
