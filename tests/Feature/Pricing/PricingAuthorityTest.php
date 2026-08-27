<?php

namespace Tests\Feature\Pricing;

use App\Actions\CreateBookingAction;
use App\Livewire\Customer\Catalog\ServiceShow;
use App\Models\Booking;
use App\Models\FlashSaleRedemption;
use App\Models\FranchiseServicePricing;
use App\Models\Service;
use App\Models\Wallet;
use App\Services\Customer\CatalogPresenter;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase D — the price a customer is SHOWN and the price they are CHARGED are
 * the same number, and the server is the only thing that decides it.
 *
 * ── The defect this suite exists for ──────────────────────────────────────
 * The price cascade has two layers and always did:
 *
 *     Service::resolvePrice($franchiseId)   franchise override -> discount_price -> base_price
 *     then the flash-sale layer             an active, scope-covering, not-sold-out sale wins
 *
 * The customer catalog applied both (CatalogPresenter). The booking path
 * applied only the first (API\BookingController quoted
 * Service::resolvePrice() straight into CreateBookingAction). A service on a
 * live 20% sale therefore rendered at 400 and booked at 500, and no test
 * anywhere placed a booking against a service that had a sale on it, so
 * nothing caught it.
 *
 * Both paths now go through FlashSaleService::effectivePriceFor(), which is
 * the same two layers in the same order — not "computed identically", the
 * same code. Every test below pins one end of that: what a screen renders,
 * or what the database ends up holding for a booking and its payment.
 *
 * ── Where the price is allowed to come from ───────────────────────────────
 * `price_quoted` on CreateBookingAction is still honoured when a caller
 * passes one, because the admin call-centre form is a real, permission-gated
 * negotiated-price feature. That is staff input, not client input: no
 * customer-facing request object accepts a price field, which is asserted
 * here rather than assumed.
 */
class PricingAuthorityTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    /** Franchise/zone + a 500 service + a customer with an address in that zone. */
    private function world(array $serviceAttributes = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();
        $service = $this->makeService($category, array_merge(['name' => 'Deep Clean'], $serviceAttributes));
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        return compact('country', 'city', 'franchise', 'zone', 'category', 'service', 'customer', 'address');
    }

    /** The number the customer-facing card actually renders, for a viewer standing in $zone. */
    private function displayedPrice(Service $service, ?int $zoneId): float
    {
        if ($zoneId) {
            app(CustomerLocationContext::class)->setZone($zoneId);
        }

        return (float) app(CatalogPresenter::class)->cards(collect([$service]))->first()['price'];
    }

    private function book(array $world, array $payload = []): Booking
    {
        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/bookings', array_merge([
                'service_id' => $world['service']->id,
                'address_id' => $world['address']->id,
                'payment_method' => 'cash',
            ], $payload))
            ->assertStatus(201);

        return Booking::latest('id')->firstOrFail();
    }

    // ==================== The mismatch itself ====================

    public function test_a_service_on_a_live_flash_sale_is_charged_the_sale_price_it_is_displayed_at(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], ['discount_type' => 'percent', 'discount_value' => 20]);

        $displayed = $this->displayedPrice($world['service'], $world['zone']->id);
        $this->assertEquals(400.00, $displayed, '20% off a 500 service must render as 400.');

        $booking = $this->book($world);

        $this->assertEquals($displayed, (float) $booking->price_quoted,
            'The booking must be quoted the price the customer was shown, not the undiscounted cascade price.');
        $this->assertEquals(400.00, (float) $booking->price_quoted);
    }

    /** The control. Without it the assertion above could pass for a reason unrelated to the sale. */
    public function test_a_service_with_no_flash_sale_is_displayed_and_charged_at_the_stored_cascade_price(): void
    {
        $world = $this->world();

        $displayed = $this->displayedPrice($world['service'], $world['zone']->id);
        $booking = $this->book($world);

        $this->assertEquals(500.00, $displayed);
        $this->assertEquals(500.00, (float) $booking->price_quoted);
        $this->assertSame(0, FlashSaleRedemption::count(), 'No sale applied, so nothing may be redeemed.');
    }

    public function test_the_rendered_service_page_shows_the_same_sale_price_the_booking_is_charged(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], ['discount_type' => 'percent', 'discount_value' => 20]);
        app(CustomerLocationContext::class)->setZone($world['zone']->id);

        Livewire::test(ServiceShow::class, ['service' => $world['service']])
            ->assertSee('400.00')
            ->assertSee('Sale price');

        $this->assertEquals(400.00, (float) $this->book($world)->price_quoted);
    }

    // ==================== The cascade's layers, together ====================

    public function test_a_flash_sale_discounts_the_franchise_override_not_the_base_price(): void
    {
        $world = $this->world();
        FranchiseServicePricing::create([
            'franchise_id' => $world['franchise']->id, 'service_id' => $world['service']->id,
            'price_override' => 400, 'is_offered' => true,
        ]);
        $this->makeFlashSale([$world['service']], ['discount_type' => 'percent', 'discount_value' => 25]);

        // 400 (the override, not the 500 base) less 25% = 300.
        $this->assertEquals(300.00, $this->displayedPrice($world['service'], $world['zone']->id));
        $this->assertEquals(300.00, (float) $this->book($world)->price_quoted);
    }

    public function test_a_flat_discount_and_a_min_final_price_floor_are_honoured_at_booking(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], [
            'discount_type' => 'flat', 'discount_value' => 900, 'min_final_price' => 99,
        ]);

        // 500 - 900 would be negative; the sale's own floor wins.
        $this->assertEquals(99.00, $this->displayedPrice($world['service'], $world['zone']->id));
        $this->assertEquals(99.00, (float) $this->book($world)->price_quoted);
    }

    public function test_a_zone_scoped_sale_that_does_not_cover_the_booking_address_is_not_applied(): void
    {
        $world = $this->world();
        [, , , $otherZone] = $this->makeFranchiseTree();
        $this->makeFlashSale([$world['service']], ['scope_type' => 'zone', 'scope_id' => $otherZone->id]);

        $this->assertEquals(500.00, $this->displayedPrice($world['service'], $world['zone']->id),
            'A sale scoped to another zone must not price for this viewer.');
        $this->assertEquals(500.00, (float) $this->book($world)->price_quoted);
    }

    public function test_a_sold_out_sale_is_neither_displayed_nor_charged(): void
    {
        $world = $this->world();
        $sale = $this->makeFlashSale([$world['service']], ['total_quantity_limit' => 1]);
        FlashSaleRedemption::create([
            'flash_sale_id' => $sale->id, 'service_id' => $world['service']->id,
            'user_id' => $this->makeCustomer()->id, 'original_price' => 500,
            'final_price' => 400, 'discount_applied' => 100,
        ]);

        $this->assertEquals(500.00, $this->displayedPrice($world['service'], $world['zone']->id));
        $this->assertEquals(500.00, (float) $this->book($world)->price_quoted);
    }

    public function test_an_expired_sale_is_neither_displayed_nor_charged(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], [
            'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay(),
        ]);

        $this->assertEquals(500.00, $this->displayedPrice($world['service'], $world['zone']->id));
        $this->assertEquals(500.00, (float) $this->book($world)->price_quoted);
    }

    // ==================== Redemption is recorded, so limits mean something ====================

    public function test_booking_a_sale_price_records_the_redemption_against_the_booking(): void
    {
        $world = $this->world();
        $sale = $this->makeFlashSale([$world['service']], ['discount_type' => 'percent', 'discount_value' => 20]);

        $booking = $this->book($world);

        $redemption = FlashSaleRedemption::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($sale->id, $redemption->flash_sale_id);
        $this->assertSame($world['customer']->id, $redemption->user_id);
        $this->assertEquals(500, $redemption->original_price);
        $this->assertEquals(400, $redemption->final_price);
        $this->assertEquals(100, $redemption->discount_applied);
    }

    /**
     * The quantity limit was previously unenforceable in practice: nothing in
     * app/ ever wrote a redemption, so remaining_quantity never moved no
     * matter how many bookings took the sale price.
     */
    public function test_a_quantity_limited_sale_stops_discounting_once_its_last_unit_is_booked(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], ['total_quantity_limit' => 1]);

        $first = $this->book($world);
        $this->assertEquals(400.00, (float) $first->price_quoted);

        $second = $this->book($world);
        $this->assertEquals(500.00, (float) $second->price_quoted,
            'Once the single discounted unit is gone the next booking pays full price.');
        $this->assertSame(1, FlashSaleRedemption::count());
    }

    // ==================== Server authority ====================

    public function test_a_manipulated_client_price_cannot_replace_the_authoritative_sale_price(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], ['discount_type' => 'percent', 'discount_value' => 20]);

        $booking = $this->book($world, ['price_quoted' => 1, 'price' => 1, 'amount' => 1]);

        $this->assertEquals(400.00, (float) $booking->price_quoted);
    }

    public function test_a_manipulated_client_price_cannot_undercut_the_plain_cascade_price(): void
    {
        $world = $this->world();

        $booking = $this->book($world, ['price_quoted' => 100]);

        $this->assertEquals(500.00, (float) $booking->price_quoted,
            'A client asking to pay 100 for a 500 service must be charged 500.');
    }

    public function test_the_customer_booking_request_does_not_accept_a_price_field_at_all(): void
    {
        $rules = (new \App\Http\Requests\Customer\StoreBookingRequest)->rules();

        foreach (['price', 'price_quoted', 'amount', 'total', 'discount'] as $field) {
            $this->assertArrayNotHasKey($field, $rules,
                "StoreBookingRequest must not accept '{$field}' — the server computes the charge.");
        }
    }

    public function test_omitting_the_price_entirely_makes_the_server_compute_it(): void
    {
        $world = $this->world();

        // Straight at the Action, with no price key present at all.
        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $world['franchise']->id,
            'zone_id' => $world['zone']->id,
            'customer_id' => $world['customer']->id,
            'service_id' => $world['service']->id,
            'address_id' => $world['address']->id,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(500.00, (float) $booking->price_quoted);
    }

    /**
     * The admin call-centre form's negotiated price is a real feature
     * (Livewire\Bookings\Index::createBooking(), gated on `bookings.create`)
     * and must keep working — this is what makes "the server computes it"
     * mean "when nobody with authority said otherwise", not "always".
     */
    public function test_an_explicitly_supplied_staff_price_is_still_honoured(): void
    {
        $world = $this->world();

        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $world['franchise']->id,
            'zone_id' => $world['zone']->id,
            'customer_id' => $world['customer']->id,
            'service_id' => $world['service']->id,
            'address_id' => $world['address']->id,
            'price_quoted' => 250,
            'payment_method' => 'cash',
        ]);

        $this->assertEquals(250.00, (float) $booking->price_quoted);
    }

    // ==================== Payment follows the booking, not the client ====================

    public function test_a_wallet_payment_debits_the_sale_price_and_records_it_as_the_captured_amount(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], ['discount_type' => 'percent', 'discount_value' => 20]);
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 1000]);

        $booking = $this->book($world, ['payment_method' => 'wallet']);

        $this->assertEquals(400.00, (float) $booking->price_quoted);
        $this->assertEquals(600.00, (float) $world['customer']->wallet->fresh()->balance,
            'The wallet must be debited the discounted 400, not the 500 list price.');
        $this->assertEquals(400.00, (float) $booking->payment->amount);
        $this->assertSame('captured', $booking->payment->status);
    }

    public function test_the_catalog_api_preview_price_is_the_price_the_booking_is_charged(): void
    {
        $world = $this->world();
        $this->makeFlashSale([$world['service']], ['discount_type' => 'percent', 'discount_value' => 20]);

        $preview = $this->getJson("/api/services?franchise_id={$world['franchise']->id}")
            ->assertOk()
            ->json('data.0.effective_price');

        $this->assertEquals(400.00, (float) $preview);
        $this->assertEquals((float) $preview, (float) $this->book($world)->price_quoted);
    }
}
