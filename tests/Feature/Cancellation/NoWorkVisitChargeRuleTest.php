<?php

namespace Tests\Feature\Cancellation;

use App\Actions\CheckInArrivalAction;
use App\Actions\CompleteBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Actions\CustomerCancelBookingAction;
use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\WithLegacyWalletPayments;
use Tests\TestCase;

/**
 * THUMB RULE — VISIT/INSPECTION CHARGE ONLY WHEN NO WORK IS DONE (CLAUDE.md).
 *
 * A ₹500 service that is carried out costs ₹500: no visit line, no waiver, no free visit used. The charge (₹149
 * here) exists only when the professional verifiably arrived and the job did NOT go ahead, and only then can the
 * Prime "Free Service Visit" forgive it (using one unit, once).
 */
class NoWorkVisitChargeRuleTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;
    use WithLegacyWalletPayments;

    private function visitFee(): void
    {
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '149');
        Setting::set('cancellation.arrival_radius_meters', '150');
    }

    private function freeVisitMember(User $customer, int $quantity = 2): EntitlementBalance
    {
        $plan = Plan::create([
            'name' => 'Prime Free Visit', 'slug' => 'pfv-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        PlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_type' => 'fee_waiver', 'quantity' => $quantity, 'usage_period' => 'monthly',
            'consumption_trigger' => 'service_completed', 'rollover_policy' => 'none',
        ]);
        $sub = Subscription::create(['subscribable_type' => User::class, 'subscribable_id' => $customer->id, 'plan_id' => $plan->id, 'status' => 'active']);
        app(\App\Services\Plans\SubscriptionService::class)->activate($sub);

        return EntitlementBalance::where('subscription_id', $sub->id)->firstOrFail();
    }

    /** Provider has arrived (verified GPS); the booking was paid online. */
    private function arrived(string $method = 'online'): array
    {
        $this->visitFee();
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'provider_en_route', 'payment_status' => 'paid', 'payment_method' => $method]);
        Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);
        $s['booking'] = app(CheckInArrivalAction::class)->execute($s['booking']->id, $s['provider'], 1.0, 1.0);

        return $s;
    }

    private function complete(array $s): Booking
    {
        $s['booking']->update(['status' => 'in_progress', 'provider_id' => $s['provider']->id]);

        return app(CompleteBookingAction::class)->execute($s['booking']->id, $s['provider'], '5678');
    }

    // ==================== completed job: never a visit charge ====================

    public function test_a_completed_job_is_billed_the_service_price_only_and_uses_no_free_visit(): void
    {
        $this->visitFee();
        $s = $this->makeAssignedBookingScenario();
        $balance = $this->freeVisitMember($s['customer']);
        $s['booking']->update(['payment_status' => 'paid']);
        $payment = Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);

        $done = $this->complete($s);

        $this->assertEquals(500.00, (float) $done->price_quoted);
        $this->assertEquals(500.00, (float) $done->price_final, 'Customer pays ₹500 — no visit charge added.');
        $this->assertEquals(0.00, (float) ($done->cancellation_fee ?? 0));
        $this->assertSame(2, $balance->fresh()->remainingQuantity(), 'The Free Service Visit is not consumed by a job that was done.');
        $this->assertSame(0, UsageLedger::count());

        $lines = app(DocumentService::class)->forPayment($payment->fresh(), 'receipt')['lines'];
        $this->assertCount(1, $lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsStringIgnoringCase('visit', $line['label']);
            $this->assertStringNotContainsStringIgnoringCase('inspection', $line['label']);
        }
        $this->assertEquals(500.00, $lines[0]['amount']);
    }

    public function test_the_customer_api_shows_a_completed_job_without_any_visit_charge(): void
    {
        $this->visitFee();
        $s = $this->makeAssignedBookingScenario();
        $done = $this->complete($s);

        $json = $this->actingAs($s['customer'], 'sanctum')->getJson('/api/bookings/'.$done->id)->assertOk()->json();
        $flat = json_encode($json);

        $this->assertStringNotContainsStringIgnoringCase('visit', $flat);
        $this->assertEquals(500, data_get($json, 'data.price_quoted', data_get($json, 'price_quoted')));
        $this->assertEquals(0, data_get($json, 'data.cancellation_fee', data_get($json, 'cancellation_fee', 0)) ?? 0);
    }

    public function test_a_completed_bundle_child_carries_no_visit_charge_and_the_bundle_total_has_none(): void
    {
        $this->visitFee();
        $s = $this->makeBookingScenario();
        app(WalletService::class)->credit($s['customer'], 5000, 'top-up', 'nw:'.Str::random(6));

        Queue::fake();
        $bundle = app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $s['customer']->id, 'payment_method' => 'wallet', 'idempotency_key' => null, 'request_fingerprint' => 'nw',
            'children' => array_fill(0, 2, ['service_id' => $s['service']->id, 'franchise_id' => $s['franchise']->id, 'zone_id' => $s['zone']->id, 'address_id' => $s['address']->id]),
        ]);

        $child = $bundle->children->first();
        $child->update(['status' => 'in_progress', 'provider_id' => $s['provider']->id, 'completion_otp' => '5678']);
        $done = app(CompleteBookingAction::class)->execute($child->id, $s['provider'], '5678');

        $this->assertEquals(1000.00, (float) $bundle->fresh()->total_price_quoted, 'Two ₹500 services: no visit lines in the bundle total.');
        $this->assertEquals(500.00, (float) $done->price_final);
        $this->assertEquals(0.00, (float) ($done->cancellation_fee ?? 0));
    }

    // ==================== no-work visit: the charge applies ====================

    public function test_a_no_work_cancel_after_verified_arrival_charges_the_visit_fee(): void
    {
        $s = $this->arrived();

        $quote = app(CustomerCancelBookingAction::class)->quote($s['booking']->fresh());

        $this->assertSame(149.0, $quote['charge']);
        $this->assertSame('visit_charge', $quote['code']);
        $this->assertEquals(351.0, $quote['refund'], 'Paid 500 online: the 149 visit charge is kept, the rest comes back.');
    }

    public function test_the_customer_facing_text_says_it_applies_only_when_no_work_is_done(): void
    {
        $this->visitFee();
        $text = app(\App\Services\Cancellation\CancellationPolicy::class)->visitChargeText();

        $this->assertStringContainsString('applies only if the professional arrives and no work is done', $text);
        $this->assertStringContainsString('there is no visit charge', $text);
        $this->assertStringNotContainsString('adjusted in your final bill', $text);
    }

    // ==================== Prime waiver: no-work case only ====================

    public function test_free_service_visit_forgives_the_charge_and_is_consumed_once_at_the_no_work_cancel(): void
    {
        $s = $this->arrived();
        $balance = $this->freeVisitMember($s['customer']);

        $quote = app(CustomerCancelBookingAction::class)->quote($s['booking']->fresh());
        $this->assertSame(0.0, $quote['charge'], 'Waived for the member.');
        $this->assertSame(149.0, $quote['breakdown']['prime_waived_amount']);
        $this->assertSame(2, $balance->fresh()->remainingQuantity(), 'Quoting consumes nothing.');

        app(CustomerCancelBookingAction::class)->execute($s['booking']->id, $s['customer']->id, 'I need to ask the owner');

        $this->assertSame(1, $balance->fresh()->remainingQuantity(), 'Exactly one free visit used.');
        $ledger = UsageLedger::where('booking_id', $s['booking']->id)->where('event_type', 'consume')->get();
        $this->assertCount(1, $ledger);
        $this->assertEquals(-149.0, (float) $ledger->first()->monetary_delta);

        // Asking again (a quote, a replay) never uses a second unit.
        app(\App\Services\Cancellation\PrimeWaiver::class)->coversVisitCharge($s['booking']->fresh());
        app(CustomerCancelBookingAction::class)->execute($s['booking']->id, $s['customer']->id, 'replay');
        $this->assertSame(1, $balance->fresh()->remainingQuantity());
    }

    /** A second arrived, paid, online booking for an existing customer. */
    private function arrivedFor(User $customer): Booking
    {
        $this->visitFee();
        $x = $this->makeAssignedBookingScenario();
        $x['booking']->update(['customer_id' => $customer->id, 'status' => 'provider_en_route', 'payment_status' => 'paid']);
        Payment::create(['booking_id' => $x['booking']->id, 'purpose' => 'booking', 'user_id' => $customer->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);

        return app(CheckInArrivalAction::class)->execute($x['booking']->id, $x['provider'], 1.0, 1.0);
    }

    public function test_two_no_work_cancels_with_one_unit_left_waive_exactly_one_and_charge_the_other(): void
    {
        $a = $this->arrived();
        $balance = $this->freeVisitMember($a['customer'], 1);
        $b = $this->arrivedFor($a['customer']);
        $cancel = app(CustomerCancelBookingAction::class);

        // Both are quoted while the single unit is still free: both quotes read "waived".
        $this->assertSame(0.0, $cancel->quote($a['booking']->fresh())['charge']);
        $this->assertSame(0.0, $cancel->quote($b->fresh())['charge']);
        $tokenB = $cancel->quote($b->fresh())['token'];

        // First cancel takes the unit.
        $cancel->execute($a['booking']->id, $a['customer']->id, 'ask the owner');
        $this->assertSame(0.0, (float) Booking::find($a['booking']->id)->cancellation_fee);
        $this->assertSame(0, $balance->fresh()->remainingQuantity());

        // The second, acting on its stale "free" quote, is NOT silently waived: the charge is now 149, the customer must reconfirm.
        try {
            $cancel->execute($b->id, $a['customer']->id, 'ask the owner too', $tokenB);
            $this->fail('A waiver was granted without a unit.');
        } catch (\App\Services\Cancellation\CancellationQuoteChangedException $e) {
            $this->assertSame(149.0, $e->quote['charge'] ?? $cancel->quote($b->fresh())['charge']);
        }
        $this->assertSame('provider_en_route', Booking::find($b->id)->status, 'still not cancelled');

        // Reconfirming at the real price charges it.
        $fresh = $cancel->quote($b->fresh());
        $this->assertSame(149.0, $fresh['charge']);
        $cancel->execute($b->id, $a['customer']->id, 'ask the owner too', $fresh['token']);
        $this->assertEquals(149.0, (float) Booking::find($b->id)->cancellation_fee);

        $this->assertSame(1, UsageLedger::where('event_type', 'consume')->count(), 'exactly one unit consumed, exactly one waiver');
        $this->assertSame(0, $balance->fresh()->remainingQuantity());
    }

    public function test_the_unit_is_consumed_in_the_same_transaction_as_the_waiver_and_rolls_back_with_it(): void
    {
        $s = $this->arrived();
        $balance = $this->freeVisitMember($s['customer'], 1);
        $waiver = app(\App\Services\Cancellation\PrimeWaiver::class);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($waiver, $s, $balance) {
                $waiver->beginSettlement($s['booking']->id);
                try {
                    $this->assertTrue($waiver->coversVisitCharge($s['booking']->fresh()));
                    $this->assertSame(0, $balance->fresh()->remainingQuantity(), 'consumed inside the transaction');
                } finally {
                    $waiver->endSettlement($s['booking']->id);
                }
                throw new \RuntimeException('cancellation failed after the waiver');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, $balance->fresh()->remainingQuantity(), 'rolled back with the cancellation: no unit lost');
        $this->assertSame(0, UsageLedger::count());
    }

    public function test_an_exhausted_free_visit_means_the_charge_applies(): void
    {
        $s = $this->arrived();
        $this->freeVisitMember($s['customer'], 0 + 1);
        $first = app(CustomerCancelBookingAction::class)->quote($s['booking']->fresh());
        $this->assertSame(0.0, $first['charge']);
        app(CustomerCancelBookingAction::class)->execute($s['booking']->id, $s['customer']->id, 'first no-work visit');

        // A second booking, same member, quantity spent.
        $this->visitFee();
        $second = $this->makeAssignedBookingScenario();
        $second['booking']->update(['customer_id' => $s['customer']->id, 'status' => 'provider_en_route', 'payment_status' => 'paid', 'arrival_verified_at' => now()]);

        $quote = app(CustomerCancelBookingAction::class)->quote($second['booking']->fresh());
        $this->assertSame(149.0, $quote['charge']);
    }

    public function test_a_cash_booking_gets_no_free_visit_and_none_is_consumed(): void
    {
        $s = $this->arrived('cash');
        $balance = $this->freeVisitMember($s['customer']);

        $quote = app(CustomerCancelBookingAction::class)->quote($s['booking']->fresh());

        $this->assertSame(149.0, $quote['charge'], 'THUMB RULE: cash bookings get no benefit, including the waiver.');
        $this->assertSame(2, $balance->fresh()->remainingQuantity());
        $this->assertSame(0, UsageLedger::count());
    }
}
