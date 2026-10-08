<?php

namespace Tests\Feature\Plans;

use App\Actions\AdminCancelBookingAction;
use App\Actions\ProposeExtraWorkAction;
use App\Exceptions\InsufficientEntitlementException;
use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\UsageLedger;
use App\Services\Plans\MembershipBenefitService;
use App\Services\Plans\UsageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\PrimeSilverFixtures;
use Tests\TestCase;

/**
 * The APPROVED 1CallFix Prime Silver printed-card specification, proven
 * end to end against the real seeder, the real SubscriptionService and the
 * real CreateBookingAction — nothing mocked.
 */
class PrimeSilverMembershipTest extends TestCase
{
    use PrimeSilverFixtures;
    use RefreshDatabase;

    private const AC = 'Premium AC Jet Pump Service';
    private const APPLIANCE = 'Appliance General Service';
    private const CREDIT = 'Home Service Credit';
    private const VISITS = '5 Free Cancellations (visit charge waived when no work is done)';
    private const PRIORITY = 'Priority-based service (allocation preference; no immediate-service guarantee)';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ============================================================ 1. the plan

    public function test_the_plan_is_priced_and_named_exactly_as_the_printed_card(): void
    {
        $plan = $this->seedPrimeSilver();

        $this->assertSame('1CallFix Prime Silver — Home Protection Plan', $plan->name);
        $this->assertSame('1999.00', (string) $plan->price);
        $this->assertSame(11, $plan->validity_months);
        $this->assertSame('11 months', $plan->validityLabel());
        $this->assertSame('customer_membership', $plan->plan_family);
        $this->assertSame('customer', $plan->eligible_actor_type);
        $this->assertTrue($plan->is_active);
        $this->assertSame(1, Plan::count(), 'Only Prime Silver exists — no Gold / Platinum / v2 plan is created.');
    }

    public function test_validity_is_eleven_calendar_months_from_the_activation_date(): void
    {
        Carbon::setTestNow('2026-03-15 09:00:00');
        $m = $this->primeMember();

        $this->assertTrue($m['subscription']->starts_at->equalTo(Carbon::parse('2026-03-15 09:00:00')));
        $this->assertTrue($m['subscription']->current_period_end->equalTo(Carbon::parse('2027-02-15 09:00:00')), 'Expected exactly 11 months after activation.');
    }

    public function test_the_five_entitlements_match_the_card_exactly(): void
    {
        $plan = $this->seedPrimeSilver();
        $by = fn (string $label) => $plan->entitlements->firstWhere('label', $label);

        $this->assertCount(5, $plan->entitlements);

        $ac = $by(self::AC);
        $this->assertSame(2, $ac->quantity);
        $this->assertSame('1500.00', (string) $ac->monetary_value, 'Advertised value ₹1,500 each.');
        $this->assertSame(3000.0, $ac->grantedMonetaryValue(), '₹1,500 × 2 = ₹3,000 in total.');
        $this->assertSame(PlanEntitlement::EFFECT_SERVICE_INCLUDED, $ac->redemption_effect);

        $appliance = $by(self::APPLIANCE);
        $this->assertSame(1, $appliance->quantity);
        $this->assertSame(PlanEntitlement::EFFECT_SERVICE_INCLUDED, $appliance->redemption_effect);

        $credit = $by(self::CREDIT);
        $this->assertSame(1, $credit->quantity);
        $this->assertSame(['electrical', 'plumbing', 'carpenter'], $credit->redeem_categories);
        $this->assertNotContains('appliance', $credit->redeem_categories, 'Appliance is a separate entitlement, never a Home Service Credit choice.');

        $visits = $by(self::VISITS);
        $this->assertSame('fee_waiver', $visits->entitlement_type);
        $this->assertSame(5, $visits->quantity);
        $this->assertNull($visits->monetary_value, 'No stored copy: the waived amount is the live cancellation.visit_fee_value setting.');
        $this->assertSame(PlanEntitlement::EFFECT_VISIT_FEE_WAIVER, $visits->redemption_effect, 'A visit waiver, never a whole-booking waiver.');

        $priority = $by(self::PRIORITY);
        $this->assertSame('priority', $priority->entitlement_type);
        $this->assertNull($priority->quantity);

        foreach ($plan->entitlements as $e) {
            $this->assertSame('none', $e->rollover_policy, "{$e->label} must not carry forward.");
            $this->assertSame('booking_created', $e->consumption_trigger);
        }
    }

    public function test_membership_terms_and_rules_are_stored_as_machine_readable_data(): void
    {
        $plan = $this->seedPrimeSilver();
        $meta = $plan->metadata;

        $this->assertTrue($meta['address_locked']);
        $this->assertFalse($meta['transferable']);
        $this->assertFalse($meta['carry_forward']);
        $this->assertFalse($meta['priority_guarantees_immediate_service']);
        $this->assertTrue($meta['spare_parts_chargeable']);
        $this->assertTrue($meta['materials_chargeable']);
        $this->assertTrue($meta['additional_visits_chargeable']);
        $this->assertTrue($meta['out_of_scope_chargeable']);
        $this->assertSame(5, $meta['free_visits_limit']);
        $this->assertSame(11, $meta['validity_months']);
        $this->assertCount(13, $meta['terms'], 'All 13 card terms are stored, in card order.');
        $this->assertStringContainsString('registered address', $meta['terms'][1]);
        $this->assertStringContainsString('does not guarantee immediate service', $meta['terms'][8]);
    }

    public function test_the_scope_of_every_benefit_is_stored_as_included_and_excluded_lists(): void
    {
        $plan = $this->seedPrimeSilver();
        $by = fn (string $label) => $plan->entitlements->firstWhere('label', $label);

        $ac = $by(self::AC);
        $this->assertContains('Jet Pump Cleaning', $ac->includes);
        $this->assertCount(7, $ac->includes);
        foreach (['Gas Charging', 'Gas Leak Rectification', 'Compressor Repair', 'PCB Repair', 'Refrigerant Refill', 'AC Installation', 'AC Shifting', 'Drain Pipe Replacement', 'Spare Parts Replacement', 'Copper Pipe Replacement'] as $excluded) {
            $this->assertContains($excluded, $ac->excludes);
        }

        $appliance = $by(self::APPLIANCE);
        $this->assertContains('Geyser Minor Service', $appliance->includes);
        $this->assertContains('Motor Replacement', $appliance->excludes);
        $this->assertContains('PCB Repairs', $appliance->excludes);

        $credit = $by(self::CREDIT);
        $this->assertContains('MCB Replacement', $credit->includes['electrical']);
        $this->assertContains('Rewiring Works', $credit->excludes['electrical']);
        $this->assertContains('Tap Replacement', $credit->includes['plumbing']);
        $this->assertContains('Water Tank Cleaning', $credit->excludes['plumbing']);
        $this->assertContains('Drawer Repair', $credit->includes['carpenter']);
        $this->assertContains('Plywood / Board Replacement', $credit->excludes['carpenter']);
        foreach (['electrical', 'plumbing', 'carpenter'] as $choice) {
            $this->assertContains('Spare Parts & Materials', $credit->excludes[$choice]);
        }
    }

    public function test_re_seeding_updates_in_place_and_keeps_ids_and_mapped_catalog_targets(): void
    {
        $catalog = $this->makePrimeCatalog();
        $plan = $this->seedPrimeSilver();
        $this->mapPrimeTargets($plan, $catalog);

        $idsBefore = $plan->entitlements->pluck('id', 'label')->all();
        $targetsBefore = \App\Models\PlanEntitlementTarget::count();
        $this->assertGreaterThan(0, $targetsBefore);

        $plan->update(['price' => 1234]); // prove the re-seed really restores the card
        $again = $this->seedPrimeSilver();

        $this->assertSame('1999.00', (string) $again->price);
        $this->assertSame($idsBefore, $again->entitlements->pluck('id', 'label')->all(), 'Entitlement ids must survive a re-seed.');
        $this->assertSame($targetsBefore, \App\Models\PlanEntitlementTarget::count(), 'Admin-mapped catalog targets must survive a re-seed.');
    }

    // ================================================== 2. AC Jet Pump  ×2

    public function test_ac_jet_pump_can_be_redeemed_twice_and_the_third_is_charged_normally(): void
    {
        $m = $this->primeMember();
        $ac = $m['catalog']['ac_jet']; // ₹1,800

        $first = $this->bookService($m['customer'], $m['address'], $ac);
        $this->assertEquals(300, $first->price_quoted, '₹1,800 less the advertised ₹1,500 value.');
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());

        $second = $this->bookService($m['customer'], $m['address'], $ac);
        $this->assertEquals(300, $second->price_quoted);
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());

        // The AC benefit is used up, so the service is charged normally. A free cancellation is
        // never taken at booking time (THUMB RULE: visit charge only when no work is done).
        $third = $this->bookService($m['customer'], $m['address'], $ac);
        $this->assertEquals(1800, $third->price_quoted, 'Third: service charged in full; no free cancellation is spent at booking.');
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity(), 'The exhausted entitlement is not consumed again.');
        $this->assertSame(5, $this->balanceOf($m['subscription'], self::VISITS)->remainingQuantity());

        $this->assertSame(2, UsageLedger::where('subscription_id', $m['subscription']->id)
            ->where('event_type', 'consume')->where('quantity_delta', -1)->count(), 'Only the 2 AC redemptions; a booking never spends a free cancellation.');
        $this->assertEquals(3000, $this->balanceOf($m['subscription'], self::AC)->consumed_monetary_value, '₹1,500 × 2 consumed.');
    }

    public function test_a_service_included_benefit_never_also_burns_a_free_visit(): void
    {
        $m = $this->primeMember();

        $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);

        $this->assertSame(5, $this->balanceOf($m['subscription'], self::VISITS)->remainingQuantity(), 'One benefit per booking.');
    }

    // ================================================ 3. Appliance General ×1

    public function test_appliance_general_service_can_be_redeemed_once_only(): void
    {
        $m = $this->primeMember();
        $appliance = $m['catalog']['appliance']; // ₹600, visit ₹150

        $first = $this->bookService($m['customer'], $m['address'], $appliance);
        $this->assertEquals(0, $first->price_quoted, 'The included service is waived (no advertised cap on this benefit).');
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::APPLIANCE)->remainingQuantity());

        $second = $this->bookService($m['customer'], $m['address'], $appliance);
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::APPLIANCE)->remainingQuantity(), 'A second attempt must not consume the entitlement.');
        $this->assertEquals(600, $second->price_quoted, 'Charged in full; no free cancellation is spent at booking.');
        $this->assertSame(1, UsageLedger::where('subscription_id', $m['subscription']->id)
            ->where('plan_entitlement_id', $m['plan']->entitlements->firstWhere('label', self::APPLIANCE)->id)
            ->where('event_type', 'consume')->count());
    }

    // ======================================== 4. Home Service Credit ×1 (one of 3)

    public function test_home_service_credit_records_electrical_and_cannot_be_reused_for_another_category(): void
    {
        $m = $this->primeMember();

        $electrical = $this->bookService($m['customer'], $m['address'], $m['catalog']['electrical']);
        $this->assertEquals(0, $electrical->price_quoted);

        $row = UsageLedger::where('booking_id', $electrical->id)->where('event_type', 'consume')->firstOrFail();
        $this->assertSame('electrical', $row->redeemed_category, 'The chosen category is stored with the redemption.');
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::CREDIT)->remainingQuantity());

        // Credit spent on Electrical: a Plumbing booking must NOT consume it again.
        $plumbing = $this->bookService($m['customer'], $m['address'], $m['catalog']['plumbing']);
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::CREDIT)->remainingQuantity());
        $this->assertSame(1, UsageLedger::where('subscription_id', $m['subscription']->id)
            ->whereNotNull('redeemed_category')->count(), 'Only the one Electrical redemption exists.');
        $this->assertEquals(500, $plumbing->price_quoted, 'Plumbing is charged in full.');
    }

    public function test_home_service_credit_can_be_spent_on_plumbing(): void
    {
        $m = $this->primeMember();

        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['plumbing']);

        $this->assertEquals(0, $booking->price_quoted);
        $this->assertSame('plumbing', UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->value('redeemed_category'));
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::CREDIT)->remainingQuantity());
    }

    public function test_home_service_credit_can_be_spent_on_carpenter(): void
    {
        $m = $this->primeMember();

        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['carpenter']);

        $this->assertEquals(0, $booking->price_quoted);
        $this->assertSame('carpenter', UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->value('redeemed_category'));
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::CREDIT)->remainingQuantity());
    }

    public function test_appliance_is_not_a_home_service_credit_choice(): void
    {
        $m = $this->primeMember();

        $this->bookService($m['customer'], $m['address'], $m['catalog']['appliance']);

        $this->assertSame(1, $this->balanceOf($m['subscription'], self::CREDIT)->remainingQuantity(), 'The Appliance service used the Appliance entitlement, not the credit.');
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::APPLIANCE)->remainingQuantity());
    }

    // ======================================== 5. Free Service Visits ×5

    public function test_a_booking_never_spends_or_prices_a_free_cancellation(): void
    {
        $m = $this->primeMember();
        $rewiring = $m['catalog']['rewiring']; // ₹800, outside the Home Credit's scope

        for ($i = 1; $i <= 6; $i++) {
            $booking = $this->bookService($m['customer'], $m['address'], $rewiring);
            $this->assertEquals(800, $booking->price_quoted, 'THUMB RULE: the visit charge is never taken off a booking that may be completed.');
        }

        $this->assertSame(5, $this->balanceOf($m['subscription'], self::VISITS)->remainingQuantity());
        $this->assertSame(0, UsageLedger::where('subscription_id', $m['subscription']->id)->count(), 'Nothing was consumed.');
    }

    public function test_a_cash_booking_gets_no_membership_benefit_and_consumes_nothing(): void
    {
        $m = $this->primeMember();

        $cash = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet'], 'cash');

        $this->assertEquals(1800, $cash->price_quoted, 'THUMB RULE: cash bookings get no benefit.');
        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
        $this->assertSame(0, UsageLedger::where('subscription_id', $m['subscription']->id)->count());
        $this->assertNull(app(MembershipBenefitService::class)->preview($m['customer'], $m['catalog']['ac_jet'], $m['address']->id, 1800.0, 'cash'));
        $this->assertNull(app(MembershipBenefitService::class)->preview($m['customer'], $m['catalog']['ac_jet'], $m['address']->id, 1800.0));

        $online = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $this->assertEquals(300, $online->price_quoted);

        $this->expectException(\App\Exceptions\OnlinePaymentRequiredException::class);
        $online->update(['payment_method' => 'cash']);
    }

    // ============================================ Home Service Credit: value cap, not cash

    public function test_a_home_service_credit_can_alternatively_be_capped_at_a_maximum_benefit_value(): void
    {
        $m = $this->primeMember();
        $credit = $m['plan']->entitlements->firstWhere('label', self::CREDIT);
        $credit->update(['monetary_value' => 499]); // admin sets the alternative configuration
        $m['catalog']['plumbing']->update(['base_price' => 900]);

        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['plumbing']);

        // The credit is opened by the customer's own balance row, which was granted before the cap
        // was configured; the cap applies to the waiver itself.
        $this->assertEquals(401, $booking->price_quoted, '₹900 less the ₹499 maximum benefit — the rest stays chargeable.');
        $row = UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->firstOrFail();
        $this->assertEquals(-499, $row->monetary_delta);
        $this->assertSame('plumbing', $row->redeemed_category);

        // One use only.
        $again = $this->bookService($m['customer'], $m['address'], $m['catalog']['plumbing']);
        $this->assertSame(0, $this->balanceOf($m['subscription'], self::CREDIT)->remainingQuantity());
        $this->assertEquals(900, $again->price_quoted, 'Second attempt: charged in full, the credit is spent.');
    }

    public function test_a_home_service_credit_is_a_service_benefit_never_wallet_or_cash_credit(): void
    {
        $m = $this->primeMember();
        $walletBefore = \App\Models\WalletTransaction::count();

        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['electrical']);

        $this->assertEquals(0, $booking->price_quoted);
        $this->assertSame($walletBefore, \App\Models\WalletTransaction::count(), 'No wallet credit was created or spent.');
        $this->assertEquals(0, (float) (\App\Models\Wallet::where('user_id', $m['customer']->id)->value('balance') ?? 0));
        $this->assertStringContainsString('not wallet or cash credit', $m['plan']->entitlements->firstWhere('label', self::CREDIT)->description);
    }

    // ==================================== 6. What always stays chargeable

    public function test_out_of_scope_work_is_charged_normally_and_consumes_nothing(): void
    {
        $m = $this->primeMember();

        $gas = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_gas']);         // AC, but explicitly excluded
        $painting = $this->bookService($m['customer'], $m['address'], $m['catalog']['painting']);   // not a covered category at all

        $this->assertEquals(2500, $gas->price_quoted, 'AC gas charging is out of scope.');
        $this->assertEquals(3000, $painting->price_quoted);
        $this->assertSame(0, UsageLedger::where('subscription_id', $m['subscription']->id)->count(), 'Nothing was consumed.');
    }

    public function test_spare_parts_and_materials_remain_fully_chargeable_after_a_benefit_is_applied(): void
    {
        $m = $this->primeMember();
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['rewiring']); // no benefit at booking
        $this->assertEquals(800, $booking->price_quoted);

        $provider = $this->makeProviderIn($m['franchise'], $m['zone']);
        $booking->update(['provider_id' => $provider->id, 'status' => 'in_progress']);

        $item = app(ProposeExtraWorkAction::class)->execute($booking->id, $provider, 'Replacement MCB (spare part)', 350.0);

        $this->assertEquals(350, $item->amount, 'The spare part is charged at its full price.');
        $this->assertEquals(800, $booking->fresh()->price_quoted, 'Extra work is a separate charge; membership never touches it.');
        $this->assertSame(5, $this->balanceOf($m['subscription'], self::VISITS)->remainingQuantity());
    }

    public function test_a_customer_without_membership_pays_the_full_price(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $catalog = $this->makePrimeCatalog();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $booking = $this->bookService($customer, $address, $catalog['ac_jet']);

        $this->assertEquals(1800, $booking->price_quoted);
        $this->assertFalse((bool) $booking->is_priority);
        $this->assertSame(0, UsageLedger::count());
    }

    // ===================================================== 7. Registered address

    public function test_benefits_apply_only_at_the_registered_address(): void
    {
        $m = $this->primeMember();
        $other = $this->makeAddress($m['customer'], $m['franchise'], $m['zone']);

        $elsewhere = $this->bookService($m['customer'], $other, $m['catalog']['ac_jet']);
        $this->assertEquals(1800, $elsewhere->price_quoted, 'A different address gets no benefit.');
        $this->assertSame(0, UsageLedger::where('subscription_id', $m['subscription']->id)->count());

        $atHome = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $this->assertEquals(300, $atHome->price_quoted);
    }

    public function test_a_membership_with_no_registered_address_grants_nothing(): void
    {
        $m = $this->primeMember();
        $m['subscription']->update(['registered_address_id' => null]);

        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);

        $this->assertEquals(1800, $booking->price_quoted);
    }

    public function test_another_customers_membership_is_never_applied_to_me(): void
    {
        $m = $this->primeMember();
        $stranger = $this->makeCustomer();
        $strangerAddress = $this->makeAddress($stranger, $m['franchise'], $m['zone']);

        $booking = $this->bookService($stranger, $strangerAddress, $m['catalog']['ac_jet']);

        $this->assertEquals(1800, $booking->price_quoted);
        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity(), "The member's balance is untouched.");
    }

    // ============================================ 8. Validity of the membership

    public function test_a_lapsed_or_paused_membership_grants_nothing_but_a_grace_period_still_does(): void
    {
        $m = $this->primeMember();
        $ac = $m['catalog']['ac_jet'];

        $m['subscription']->update(['status' => 'paused']);
        $this->assertEquals(1800, $this->bookService($m['customer'], $m['address'], $ac)->price_quoted, 'Paused.');

        $m['subscription']->update(['status' => 'active', 'current_period_end' => now()->subMinute()]);
        $this->assertEquals(1800, $this->bookService($m['customer'], $m['address'], $ac)->price_quoted, 'Past its period end, even before the cron has flipped the status.');

        $m['subscription']->update(['status' => 'grace_period']);
        $this->assertEquals(300, $this->bookService($m['customer'], $m['address'], $ac)->price_quoted, 'Grace period keeps benefits available.');
    }

    // ================================== 9. Timing, cancellation & idempotency

    public function test_previewing_a_quote_never_consumes_anything(): void
    {
        $m = $this->primeMember();

        $preview = app(MembershipBenefitService::class)->preview($m['customer'], $m['catalog']['ac_jet'], $m['address']->id, 1800.0, 'online');

        $this->assertNotNull($preview);
        $this->assertEquals(300, $preview['adjusted_price']);
        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
        $this->assertSame(0, UsageLedger::count());
    }

    public function test_the_same_booking_can_never_consume_a_benefit_twice(): void
    {
        $m = $this->primeMember();
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);

        $again = app(MembershipBenefitService::class)->applyForBooking($m['customer'], $m['catalog']['ac_jet'], $booking->fresh(), 1800.0);

        $this->assertNull($again, 'A repeated request for the same booking applies nothing.');
        $this->assertSame(1, UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->count());
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
    }

    public function test_the_usage_service_returns_the_original_row_for_a_repeated_consume_on_the_same_booking(): void
    {
        $m = $this->primeMember();
        $usage = app(UsageService::class);
        $balance = $this->balanceOf($m['subscription'], self::AC);
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['painting']); // no benefit applies to it

        $first = $usage->consume($balance, 1, 0.0, $booking, false, null, 'first');
        $repeat = $usage->consume($balance->fresh(), 1, 0.0, $booking, false, null, 'repeat / retried callback');

        $this->assertSame($first->id, $repeat->id, 'A retried request gets the ORIGINAL ledger row back.');
        $this->assertSame(1, UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->count());
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity(), 'Consumed exactly once.');
    }

    public function test_cancelling_before_service_gives_the_benefit_back_and_it_can_be_used_again(): void
    {
        $m = $this->primeMember();
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());

        app(AdminCancelBookingAction::class)->execute($booking->id, 'customer changed mind');

        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity(), 'The benefit is restored.');
        $this->assertSame(1, UsageLedger::where('booking_id', $booking->id)->where('event_type', 'reverse')->count());

        // A repeated cancellation is refused and restores nothing twice.
        try {
            app(AdminCancelBookingAction::class)->execute($booking->id, 'again');
            $this->fail('A second cancellation should have been refused.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());

        // And it can genuinely be redeemed again afterwards.
        $rebook = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $this->assertEquals(300, $rebook->price_quoted);
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
    }

    public function test_reversing_the_same_usage_twice_restores_it_only_once(): void
    {
        $m = $this->primeMember();
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $consume = UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->firstOrFail();

        $first = app(UsageService::class)->reverse($consume, 'admin');
        $second = app(UsageService::class)->reverse($consume, 'admin');

        $this->assertNotNull($first);
        $this->assertNull($second, 'The second reversal is a no-op.');
        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
    }

    public function test_a_limited_entitlement_can_never_be_consumed_below_zero(): void
    {
        $m = $this->primeMember();
        $balance = $this->balanceOf($m['subscription'], self::APPLIANCE);       // quantity 1
        $usage = app(UsageService::class);

        $usage->consume($balance, 1, 0.0);
        $this->assertSame(0, $balance->fresh()->remainingQuantity());

        $this->expectException(InsufficientEntitlementException::class);
        try {
            $usage->consume($balance->fresh(), 1, 0.0);
        } finally {
            $this->assertSame(0, $balance->fresh()->remainingQuantity());
            $this->assertSame(1, UsageLedger::where('plan_entitlement_id', $balance->plan_entitlement_id)->count(), 'The refused consume wrote no ledger row.');
        }
    }
}
