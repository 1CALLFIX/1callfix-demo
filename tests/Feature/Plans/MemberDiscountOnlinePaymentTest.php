<?php

namespace Tests\Feature\Plans;

use App\Actions\CreateBookingAction;
use App\Exceptions\OnlinePaymentRequiredException;
use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Models\Wallet;
use App\Services\Plans\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * D6 (docs/COUPON_HARDENING_FINAL.md) — THUMB RULE: a Prime / membership price DISCOUNT is a benefit, so it
 * only exists on an online payment. On any other method the booking pays the normal price and the discount
 * entitlement is NOT consumed. The free-visit waiver, the membership purchase and quantity entitlements are
 * out of scope and untouched.
 */
class MemberDiscountOnlinePaymentTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function world(array $entitlement = ['entitlement_type' => 'percentage_discount', 'percentage_value' => 20, 'quantity' => 5]): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService(); // base_price 500
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('payment.wallet_enabled', '1');

        $plan = Plan::create([
            'name' => 'QA Membership', 'slug' => 'qa-membership-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly', 'price' => 0,
            'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        PlanEntitlement::create(array_merge([
            'plan_id' => $plan->id, 'usage_period' => 'monthly', 'consumption_trigger' => 'booking_created', 'rollover_policy' => 'none',
        ], $entitlement));
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $subscription = Subscription::findOrFail($result['subscription_id']);

        return compact('franchise', 'zone', 'service', 'customer', 'address', 'subscription');
    }

    private function book(array $w, string $method): Booking
    {
        Queue::fake();

        return app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => $method,
        ]);
    }

    public function test_cash_booking_gets_no_member_discount_and_consumes_nothing(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'cash');

        $this->assertEquals(500.00, (float) $booking->price_quoted);
        $this->assertSame(0, UsageLedger::where('booking_id', $booking->id)->count());
        $this->assertSame(5, EntitlementBalance::where('subscription_id', $w['subscription']->id)->firstOrFail()->remainingQuantity());
    }

    public function test_cash_plus_online_style_methods_get_no_member_discount(): void
    {
        foreach (['cash+online', 'split', 'cash_online'] as $method) {
            $w = $this->world();
            $this->assertEquals(500.00, (float) $this->book($w, $method)->price_quoted, "{$method} must not unlock the member discount.");
        }
    }

    public function test_online_booking_gets_the_member_discount(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');

        $this->assertEquals(400.00, (float) $booking->price_quoted);
        $this->assertSame(4, EntitlementBalance::where('subscription_id', $w['subscription']->id)->firstOrFail()->remainingQuantity());
    }

    public function test_wallet_booking_gets_the_member_discount(): void
    {
        $w = $this->world();
        Wallet::create(['user_id' => $w['customer']->id, 'balance' => 1000]);

        $this->assertEquals(400.00, (float) $this->book($w, 'wallet')->price_quoted);
    }

    public function test_a_member_priced_booking_can_never_switch_to_cash_afterwards(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');

        $this->expectException(OnlinePaymentRequiredException::class);
        $booking->update(['payment_method' => 'cash']);
    }

    public function test_a_quantity_entitlement_is_not_a_discount_and_is_untouched_on_cash(): void
    {
        $w = $this->world(['entitlement_type' => 'quantity', 'quantity' => 3]);
        $booking = $this->book($w, 'cash');

        $this->assertEquals(500.00, (float) $booking->price_quoted);
        $this->assertSame(2, EntitlementBalance::where('subscription_id', $w['subscription']->id)->firstOrFail()->remainingQuantity(),
            'Only discount entitlements are online-only; the quantity unit is still consumed.');
    }

    public function test_an_existing_cash_booking_with_a_member_price_is_unchanged(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');
        Booking::withoutEvents(fn () => $booking->forceFill(['payment_method' => 'cash'])->save());

        $booking->refresh();
        $booking->update(['status' => 'confirmed']);

        $this->assertEquals(400.00, (float) $booking->fresh()->price_quoted);
    }
}
