<?php

namespace Tests\Feature\Plans;

use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Services\Plans\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Proves that a customer MEMBERSHIP actually changes what a booking costs.
 *
 * Background: this codebase has no class called "MembershipService".
 * Customer membership is the Plan Engine's `plan_family = 'customer_membership'`
 * (App\Services\Plans\*), and the one place a membership touches money for a
 * Service booking is App\Actions\CreateBookingAction, which calls
 * EntitlementService::resolveAndConsumeForBooking() and writes the returned
 * price back onto the booking.
 *
 * That hook had no test anywhere in tests/ before this file: PlanEngineSmokeTest
 * covers subscribe/cancel/pause/renew and the granting of entitlement BALANCES,
 * and CustomerBookingApiTest covers booking creation with no subscription in
 * play — neither one drives a subscribed customer through booking creation, so
 * nothing proved the price was actually adjusted.
 *
 * Nothing is mocked here. A real Plan is created, subscribed through the real
 * SubscriptionService, and the booking is placed through the real
 * POST /api/bookings endpoint; the assertions read the persisted
 * `bookings.price_quoted`, the `entitlement_balances` row and the
 * `usage_ledgers` audit row that the engine wrote.
 */
class MembershipPricingEntitlementTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function makeMembershipPlan(array $entitlement): Plan
    {
        $plan = Plan::create([
            'name' => 'QA Membership',
            'slug' => 'qa-membership-'.Str::random(6),
            'plan_family' => 'customer_membership',
            'scope_type' => 'global',
            'eligible_actor_type' => 'customer',
            'billing_cycle' => 'monthly',
            'price' => 0,
            'stacking_strategy' => 'exclusive',
            'is_active' => true,
        ]);

        PlanEntitlement::create(array_merge([
            'plan_id' => $plan->id,
            'usage_period' => 'monthly',
            'consumption_trigger' => 'booking_created',
            'rollover_policy' => 'none',
        ], $entitlement));

        return $plan;
    }

    public function test_a_membership_percentage_discount_changes_the_price_the_booking_is_charged(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService(); // base_price 500
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $plan = $this->makeMembershipPlan([
            'entitlement_type' => 'percentage_discount',
            'percentage_value' => 20,
            'quantity' => 5,
        ]);

        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $subscription = Subscription::findOrFail($result['subscription_id']);
        $this->assertSame('active', $subscription->status);

        $balanceBefore = EntitlementBalance::where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame(5, $balanceBefore->remainingQuantity());

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [
                'service_id' => $service->id,
                'address_id' => $address->id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201);

        $booking = Booking::firstOrFail();

        // The price the server actually recorded — 500 base, less 20%.
        $this->assertEquals(400, $booking->price_quoted);

        // The entitlement was really consumed, not merely read.
        $this->assertSame(4, $balanceBefore->fresh()->remainingQuantity());

        $ledger = UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->firstOrFail();
        $this->assertEquals(-100, $ledger->monetary_delta, 'The ledger must record the 100 actually discounted.');
    }

    /**
     * Control: the identical booking, placed by a customer with no
     * membership, is charged the full price. Without this, the assertion
     * above could pass for a reason unrelated to the entitlement.
     */
    public function test_the_same_booking_without_a_membership_is_charged_the_full_price(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [
                'service_id' => $service->id,
                'address_id' => $address->id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201);

        $this->assertEquals(500, Booking::firstOrFail()->price_quoted);
        $this->assertSame(0, UsageLedger::count());
    }

    // ============================== fee_waiver = "Free Service Visit": NO-WORK visit charge only ==============================

    /** A ₹500 service, a member with a `fee_waiver` free visit, and the booking placed through the real API. */
    private function memberWithFreeVisit(string $method): array
    {
        \App\Models\Setting::set('cancellation.visit_fee_type', 'flat'); // before the booking: the policy snapshot freezes it
        \App\Models\Setting::set('cancellation.visit_fee_value', '149');

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService(); // base_price 500
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $plan = $this->makeMembershipPlan(['entitlement_type' => 'fee_waiver', 'quantity' => 1]);
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $balance = EntitlementBalance::where('subscription_id', $result['subscription_id'])->firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => $method])
            ->assertStatus(201);

        return [Booking::firstOrFail(), $balance];
    }

    /**
     * THUMB RULE (CLAUDE.md): the visit charge exists only when no work is done, so a booking that is going ahead is
     * never repriced by it. A ₹500 service stays ₹500; the free visit is not consumed at booking time.
     */
    public function test_a_fee_waiver_never_reprices_a_booking_and_is_not_consumed_at_booking_time(): void
    {
        foreach (['online', 'cash'] as $method) {
            UsageLedger::query()->delete();
            Booking::query()->forceDelete();
            [$booking, $balance] = $this->memberWithFreeVisit($method);

            $this->assertEquals(500, $booking->price_quoted, "{$method}: the service price is still charged in full");
            $this->assertSame(1, $balance->fresh()->remainingQuantity(), "{$method}: the free visit is not used by a booking that goes ahead");
            $this->assertSame(0, UsageLedger::count());
        }
    }

    public function test_included_service_entitlements_are_unchanged_by_the_visit_charge_rule(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        // An included-service ("quantity") entitlement: the price is untouched, one unit is consumed, exactly as before.
        $plan = $this->makeMembershipPlan(['entitlement_type' => 'quantity', 'quantity' => 2]);
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $balance = EntitlementBalance::where('subscription_id', $result['subscription_id'])->firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'online'])
            ->assertStatus(201);

        $this->assertEquals(500, Booking::firstOrFail()->price_quoted);
        $this->assertSame(1, $balance->fresh()->remainingQuantity());
    }

    public function test_the_seeded_prime_silver_free_visit_is_not_reached_by_the_automatic_booking_pricing(): void
    {
        // Prime Silver's entitlements (all 5, incl. fee_waiver) are redeemed explicitly (RedeemEntitlementAction) and are
        // seeded with consumption_trigger = service_completed, so the booking-time pricing resolver never sees them.
        \App\Models\Setting::set('cancellation.visit_fee_value', '149');
        $this->seed(\Database\Seeders\PrimeSilverPlanSeeder::class);
        $plan = Plan::where('slug', '1callfix-prime-silver')->firstOrFail();
        $this->assertSame(['service_completed'], $plan->entitlements()->pluck('consumption_trigger')->unique()->values()->all());

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        // An ACTIVE member (the paid plan would otherwise open a Razorpay order when subscribing).
        Subscription::create(['subscribable_type' => \App\Models\User::class, 'subscribable_id' => $customer->id, 'plan_id' => $plan->id, 'status' => 'active']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'online'])
            ->assertStatus(201);

        $this->assertEquals(500, Booking::firstOrFail()->price_quoted, 'a Prime member is not repriced by the automatic resolver');
        $this->assertSame(0, UsageLedger::count());
    }
}
