<?php

namespace Tests\Feature\Pricing;

use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\EntitlementBalance;
use App\Models\FlashSaleRedemption;
use App\Models\FranchiseServicePricing;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Models\Wallet;
use App\Services\Plans\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E2 — the multi-service bundle is priced by the SAME server-side
 * authority as a single booking, and the client can never move the number.
 *
 * Every child price flows through CreateBookingAction::createWithinTransaction()
 * → resolveAuthoritativePrice() → FlashSaleService::effectivePriceFor() — the
 * one Phase-D cascade. This suite pins the database end of it for a bundle:
 * child `price_quoted`, the bundle `total_price_quoted` (their plain sum), the
 * recorded flash redemption, the recorded entitlement consumption, and the
 * aggregate wallet debit. It never asserts against a number the request
 * supplied.
 */
class BundlePricingAuthorityTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private static int $seq = 0;

    private function world(float $priceA = 500, float $priceB = 500): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $franchise->update(['code' => 'BPA'.str_pad((string) self::$seq++, 3, '0', STR_PAD_LEFT)]);

        $category = $this->makeCategory();
        $serviceA = $this->makeService($category, ['name' => 'Service A', 'base_price' => $priceA]);
        $serviceB = $this->makeService($category, ['name' => 'Service B', 'base_price' => $priceB]);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        return compact('country', 'city', 'franchise', 'zone', 'category', 'serviceA', 'serviceB', 'customer', 'address');
    }

    private function createBundle(array $world, array $overrides = []): BookingBundle
    {
        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', array_merge([
                'payment_method' => 'cash',
                'services' => [
                    ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
                    ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
                ],
            ], $overrides))
            ->assertStatus(201);

        return BookingBundle::latest('id')->firstOrFail();
    }

    private function childFor(BookingBundle $bundle, int $serviceId): Booking
    {
        return Booking::where('booking_bundle_id', $bundle->id)->where('service_id', $serviceId)->firstOrFail();
    }

    // ============================== C. client price manipulation ==============================

    public function test_a_manipulated_client_price_cannot_replace_the_authoritative_child_prices(): void
    {
        $world = $this->world(1000, 500);
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 5000]);

        $bundle = $this->createBundle($world, [
            'payment_method' => 'wallet',
            'price' => 1, 'amount' => 1, 'total' => 1, 'total_price_quoted' => 1,
            'services' => [
                ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id, 'price' => 1, 'price_quoted' => 1, 'amount' => 1],
                ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id, 'price' => 1, 'price_quoted' => 1, 'amount' => 1],
            ],
        ]);

        $this->assertEqualsWithDelta(1000.0, (float) $this->childFor($bundle, $world['serviceA']->id)->price_quoted, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $this->childFor($bundle, $world['serviceB']->id)->price_quoted, 0.001);
        $this->assertEqualsWithDelta(1500.0, (float) $bundle->total_price_quoted, 0.001);

        // the debit followed the authoritative total, not the client's "1"
        $this->assertEqualsWithDelta(3500.0, (float) $world['customer']->wallet->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(1500.0, (float) $bundle->payment->amount, 0.001);
    }

    // ============================== D. no price supplied ==============================

    public function test_with_no_price_in_the_request_the_server_computes_every_child_price(): void
    {
        $world = $this->world(700, 300);

        $bundle = $this->createBundle($world);

        $this->assertEqualsWithDelta(700.0, (float) $this->childFor($bundle, $world['serviceA']->id)->price_quoted, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $this->childFor($bundle, $world['serviceB']->id)->price_quoted, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $bundle->total_price_quoted, 0.001);
    }

    // ============================== E. flash-sale pricing ==============================

    public function test_a_child_on_a_live_flash_sale_is_priced_and_the_bundle_total_uses_the_sale_price(): void
    {
        $world = $this->world(500, 500);
        // 20% off Service A only
        $sale = $this->makeFlashSale([$world['serviceA']], ['discount_type' => 'percent', 'discount_value' => 20]);

        $bundle = $this->createBundle($world);

        $childA = $this->childFor($bundle, $world['serviceA']->id);
        $childB = $this->childFor($bundle, $world['serviceB']->id);

        $this->assertEqualsWithDelta(400.0, (float) $childA->price_quoted, 0.001, 'Service A: 20% off 500 = 400');
        $this->assertEqualsWithDelta(500.0, (float) $childB->price_quoted, 0.001, 'Service B: not on sale');
        $this->assertEqualsWithDelta(900.0, (float) $bundle->total_price_quoted, 0.001);

        // redemption recorded against the child, via the same engine the
        // single-booking path uses — no second pricing/redemption implementation
        $redemption = FlashSaleRedemption::where('booking_id', $childA->id)->firstOrFail();
        $this->assertSame($sale->id, $redemption->flash_sale_id);
        $this->assertEqualsWithDelta(500.0, (float) $redemption->original_price, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $redemption->final_price, 0.001);
        $this->assertSame(1, FlashSaleRedemption::count());
    }

    public function test_a_quantity_limited_sale_only_discounts_one_child_when_both_target_it(): void
    {
        $world = $this->world(500, 500);
        // one unit total, targets BOTH services in the bundle
        $this->makeFlashSale([$world['serviceA'], $world['serviceB']], ['total_quantity_limit' => 1]);

        $bundle = $this->createBundle($world);

        $prices = Booking::where('booking_bundle_id', $bundle->id)->pluck('price_quoted')->map(fn ($p) => (float) $p)->sort()->values()->all();
        $this->assertEqualsWithDelta(400.0, $prices[0], 0.001, 'first child got the single discounted unit');
        $this->assertEqualsWithDelta(500.0, $prices[1], 0.001, 'second child pays full price — unit already gone');
        $this->assertEqualsWithDelta(900.0, (float) $bundle->total_price_quoted, 0.001);
        $this->assertSame(1, FlashSaleRedemption::count());
    }

    // ============================== F. membership pricing ==============================

    public function test_a_membership_discount_is_applied_per_child_and_aggregated(): void
    {
        $world = $this->world(500, 500);

        $plan = Plan::create([
            'name' => 'QA Membership', 'slug' => 'qa-membership-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        PlanEntitlement::create([
            'plan_id' => $plan->id, 'usage_period' => 'monthly', 'consumption_trigger' => 'booking_created',
            'rollover_policy' => 'none', 'entitlement_type' => 'percentage_discount',
            'percentage_value' => 20, 'quantity' => 5,
        ]);

        $result = app(SubscriptionService::class)->initiateSubscribe($world['customer'], 'customer', $plan);
        $subscription = Subscription::findOrFail($result['subscription_id']);
        $this->assertSame('active', $subscription->status);

        $bundle = $this->createBundle($world);

        // 20% off each 500 child = 400 each
        $this->assertEqualsWithDelta(400.0, (float) $this->childFor($bundle, $world['serviceA']->id)->price_quoted, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $this->childFor($bundle, $world['serviceB']->id)->price_quoted, 0.001);
        $this->assertEqualsWithDelta(800.0, (float) $bundle->total_price_quoted, 0.001);

        // consumed once per child, aggregated against the one balance
        $balance = EntitlementBalance::where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame(3, $balance->remainingQuantity(), '5 units - 2 children = 3');
        $this->assertSame(2, UsageLedger::where('event_type', 'consume')->count());
    }

    // ============================== G. franchise pricing override ==============================

    public function test_a_franchise_price_override_is_applied_per_child(): void
    {
        $world = $this->world(500, 500);
        FranchiseServicePricing::create([
            'franchise_id' => $world['franchise']->id, 'service_id' => $world['serviceA']->id,
            'price_override' => 350, 'is_offered' => true,
        ]);

        $bundle = $this->createBundle($world);

        $this->assertEqualsWithDelta(350.0, (float) $this->childFor($bundle, $world['serviceA']->id)->price_quoted, 0.001, 'franchise override wins');
        $this->assertEqualsWithDelta(500.0, (float) $this->childFor($bundle, $world['serviceB']->id)->price_quoted, 0.001, 'no override → base price');
        $this->assertEqualsWithDelta(850.0, (float) $bundle->total_price_quoted, 0.001);
    }

    public function test_a_flash_sale_discounts_the_franchise_override_for_a_bundle_child(): void
    {
        $world = $this->world(500, 500);
        FranchiseServicePricing::create([
            'franchise_id' => $world['franchise']->id, 'service_id' => $world['serviceA']->id,
            'price_override' => 400, 'is_offered' => true,
        ]);
        $this->makeFlashSale([$world['serviceA']], ['discount_type' => 'percent', 'discount_value' => 25]);

        $bundle = $this->createBundle($world);

        // 400 (override, not 500 base) less 25% = 300
        $this->assertEqualsWithDelta(300.0, (float) $this->childFor($bundle, $world['serviceA']->id)->price_quoted, 0.001);
        $this->assertEqualsWithDelta(800.0, (float) $bundle->total_price_quoted, 0.001);
    }
}
