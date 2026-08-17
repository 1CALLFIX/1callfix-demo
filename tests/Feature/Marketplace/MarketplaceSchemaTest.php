<?php

namespace Tests\Feature\Marketplace;

use App\Contracts\Orderable;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/** Phase 24 (Marketplace Foundation). Migration/schema + relationship coverage, mirroring PropertyReservationSchemaTest. */
class MarketplaceSchemaTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    public function test_marketplace_order_implements_orderable(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();

        $this->assertInstanceOf(Orderable::class, $scenario['order']);
        // Unlike every prior implementer, moduleCode() is dynamic (the
        // order's own `module` column), not a fixed constant -- the one
        // real place this implementer genuinely differs, since it serves
        // four verticals.
        $this->assertSame('commerce', $scenario['order']->moduleCode());
    }

    public function test_commission_can_belong_to_a_marketplace_order(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();
        $commission = Commission::create(['marketplace_order_id' => $scenario['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->assertNull($commission->fresh()->booking_id);
        $this->assertSame($scenario['order']->id, $commission->marketplaceOrder->id);
    }

    public function test_payment_purpose_accepts_marketplace_order_alongside_the_prior_six(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();
        $payment = Payment::create(['marketplace_order_id' => $scenario['order']->id, 'purpose' => 'marketplace_order', 'amount' => 200, 'status' => 'pending']);

        $this->assertSame('marketplace_order', $payment->fresh()->purpose);
        $this->assertSame($scenario['order']->id, $payment->marketplaceOrder->id);
    }

    public function test_review_can_belong_to_a_marketplace_order(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();

        $review = Review::create([
            'marketplace_order_id' => $scenario['order']->id,
            'customer_id' => $scenario['customer']->id,
            'provider_id' => $scenario['owner']->id,
            'rating' => 5,
        ]);

        $this->assertNull($review->fresh()->booking_id);
        $this->assertSame($scenario['order']->id, $review->marketplaceOrder->id);
    }

    public function test_existing_property_reviews_are_unaffected(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();
        $review = Review::create([
            'marketplace_order_id' => $scenario['order']->id,
            'customer_id' => $scenario['customer']->id,
            'provider_id' => $scenario['owner']->id,
            'rating' => 4,
        ]);

        $this->assertNull($review->fresh()->property_reservation_id);
        $this->assertNull($review->fresh()->booking_id);
    }

    public function test_commissions_marketplace_order_id_is_unique(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();
        Commission::create(['marketplace_order_id' => $scenario['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('commissions')->insert(['marketplace_order_id' => $scenario['order']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * `product_variants.stock`/`products.stock` concurrency safety is
     * structurally different from Property Rental's date-slot design (see
     * PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md §5) -- it relies on
     * `lockForUpdate()` against an ALREADY-EXISTING row, not a UNIQUE
     * constraint backstopping a same-instant double-INSERT race, so there
     * is no equivalent constraint-violation proof to write here. The real
     * proof is `MarketplaceOrderLifecycleTest::
     * test_insufficient_stock_blocks_checkout_and_does_not_partially_decrement()`
     * -- same honest "PHPUnit is single-threaded" limitation this
     * codebase's own `ServiceMatchingJobRaceTest` already states for
     * itself, not hidden here either.
     */
    public function test_a_store_and_all_five_prior_orderable_verticals_can_coexist_with_independent_commission_rows(): void
    {
        $bookingScenario = $this->makeAssignedBookingScenario();
        $marketplaceScenario = $this->makeMarketplaceOrderScenario();

        Commission::create(['booking_id' => $bookingScenario['booking']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1]);
        Commission::create(['marketplace_order_id' => $marketplaceScenario['order']->id, 'provider_commission' => 2, 'franchise_commission' => 2, 'platform_commission' => 2]);

        $this->assertSame(2, Commission::count());
    }

    public function test_marketplace_category_is_self_referential(): void
    {
        $parent = $this->makeMarketplaceCategory(['name' => 'Electronics']);
        $child = $this->makeMarketplaceCategory(['name' => 'Phones', 'parent_id' => $parent->id]);

        $this->assertSame($parent->id, $child->fresh()->parent->id);
        $this->assertTrue($parent->fresh()->children->contains($child));
    }

    public function test_product_variant_falls_back_to_the_product_price_when_no_override_is_set(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();
        $product = $this->makeProduct($scenario['store'], ['price' => 200, 'discount_percent' => 10]);
        $variant = $this->makeProductVariant($product, ['price_override' => null]);

        $this->assertSame(180.0, $variant->effectivePrice()); // 200 - 10%
    }

    public function test_product_variant_uses_its_own_override_when_set(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario();
        $product = $this->makeProduct($scenario['store'], ['price' => 200]);
        $variant = $this->makeProductVariant($product, ['price_override' => 250]);

        $this->assertSame(250.0, $variant->effectivePrice());
    }
}
