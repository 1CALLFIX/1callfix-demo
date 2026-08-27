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

    /**
     * `fee_waiver` is the "free booking" membership benefit the readiness
     * matrix describes. It must take the price to zero — and, once the quota
     * is spent, the next booking must fall back to the full price rather
     * than staying free.
     */
    public function test_a_fee_waiver_membership_makes_the_booking_free_until_the_quota_is_spent(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $plan = $this->makeMembershipPlan([
            'entitlement_type' => 'fee_waiver',
            'quantity' => 1, // exactly one free booking
        ]);

        app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);

        $book = fn () => $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [
                'service_id' => $service->id,
                'address_id' => $address->id,
                'payment_method' => 'cash',
            ])
            ->assertStatus(201);

        $book();
        $first = Booking::orderBy('id')->firstOrFail();
        $this->assertEquals(0, $first->price_quoted, 'The first booking must be waived to zero.');

        $book();
        $second = Booking::orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertEquals(500, $second->price_quoted, 'An exhausted quota must fall back to the full price.');
    }
}
