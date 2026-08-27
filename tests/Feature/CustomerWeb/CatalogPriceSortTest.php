<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\CategoryShow;
use App\Livewire\Customer\Catalog\ServiceIndex;
use App\Models\Booking;
use App\Models\FranchiseServicePricing;
use App\Models\Service;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase C close-out — the catalog's price sorts must order by the price a
 * booking is ACTUALLY quoted, not by an approximation of it.
 *
 * The gap this suite closes: the sorts used to order by
 * `coalesce(discount_price, base_price)` — the service row's own two
 * columns — while the price on each card, and the price
 * API\BookingController quotes into CreateBookingAction, both come from
 * Service::resolvePrice($franchiseId), which consults the viewer's
 * FranchiseServicePricing override FIRST. For any franchise that overrides a
 * price, the grid could therefore be sorted by numbers that were not the
 * numbers printed on the cards.
 *
 * Every fixture below is built so the franchise override INVERTS the stored
 * cascade's order. That is deliberate: if the implementation regressed to
 * the old column-only ordering, these assertions fail rather than passing by
 * coincidence.
 *
 * The "authoritative" order is not asserted from a hard-coded list alone —
 * it is derived by actually creating a booking for each service through the
 * real POST /api/bookings path and reading back the persisted
 * `bookings.price_quoted`, which is the server-authoritative payable price.
 */
class CatalogPriceSortTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    /**
     * Three services whose franchise-override order is the exact reverse of
     * their stored-column order.
     *
     *   name      base   discount   override   stored cascade   resolvePrice
     *   Alpha      100          -          -              100            100
     *   Bravo      900          -         50              900             50
     *   Charlie    300        200          -              200            200
     *
     * stored-cascade ascending : Charlie(200) is 2nd, Alpha(100) 1st, Bravo(900) last
     * authoritative ascending  : Bravo(50), Alpha(100), Charlie(200)
     *
     * @return array{0: \App\Models\ServiceCategory, 1: \App\Models\Franchise, 2: \App\Models\Zone, 3: array<string, Service>}
     */
    private function scenario(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();

        $services = [
            'Alpha' => $this->makeService($category, ['name' => 'Alpha', 'base_price' => 100]),
            'Bravo' => $this->makeService($category, ['name' => 'Bravo', 'base_price' => 900]),
            'Charlie' => $this->makeService($category, ['name' => 'Charlie', 'base_price' => 300, 'discount_price' => 200]),
        ];

        FranchiseServicePricing::create([
            'franchise_id' => $franchise->id,
            'service_id' => $services['Bravo']->id,
            'price_override' => 50,
            'is_offered' => true,
        ]);

        return [$category, $franchise, $zone, $services];
    }

    /**
     * The server-authoritative payable price for each service, obtained by
     * actually booking it through the real API path — not by re-calling the
     * pricing helper the screen uses.
     *
     * @param  array<string, Service>  $services
     * @return array<string, float> service name => bookings.price_quoted
     */
    private function checkoutPrices(array $services, $franchise, $zone): array
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $prices = [];

        foreach ($services as $name => $service) {
            $this->actingAs($customer, 'sanctum')
                ->postJson('/api/bookings', [
                    'service_id' => $service->id,
                    'address_id' => $address->id,
                    'payment_method' => 'cash',
                ])
                ->assertStatus(201);

            $prices[$name] = (float) Booking::where('service_id', $service->id)->latest('id')->firstOrFail()->price_quoted;
        }

        return $prices;
    }

    /** @param  array<string, float>  $prices */
    private function orderedByCheckoutPrice(array $prices, string $direction = 'asc'): array
    {
        $direction === 'asc' ? asort($prices) : arsort($prices);

        return array_keys($prices);
    }

    private function renderedOrder($component): array
    {
        return $component->viewData('cards')->pluck('name')->all();
    }

    // ==================== The gap, closed ====================

    public function test_price_low_sort_matches_the_authoritative_checkout_price_order(): void
    {
        [, $franchise, $zone, $services] = $this->scenario();

        $checkout = $this->checkoutPrices($services, $franchise, $zone);

        // The override really is what checkout charges — this is the order
        // the screen has to reproduce.
        $this->assertSame(['Alpha' => 100.0, 'Bravo' => 50.0, 'Charlie' => 200.0], $checkout);

        app(CustomerLocationContext::class)->setZone($zone->id);

        $rendered = $this->renderedOrder(Livewire::test(ServiceIndex::class)->set('sort', 'price_low'));

        $this->assertSame($this->orderedByCheckoutPrice($checkout), $rendered);
        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], $rendered);

        // Guard against a silent regression to the old column-only sort,
        // which would have produced Alpha, Charlie, Bravo.
        $this->assertNotSame(['Alpha', 'Charlie', 'Bravo'], $rendered, 'Sort fell back to the stored column cascade.');
    }

    public function test_price_high_sort_matches_the_authoritative_checkout_price_order(): void
    {
        [, $franchise, $zone, $services] = $this->scenario();

        $checkout = $this->checkoutPrices($services, $franchise, $zone);

        app(CustomerLocationContext::class)->setZone($zone->id);

        $rendered = $this->renderedOrder(Livewire::test(ServiceIndex::class)->set('sort', 'price_high'));

        $this->assertSame($this->orderedByCheckoutPrice($checkout, 'desc'), $rendered);
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $rendered);
    }

    public function test_the_category_screen_sorts_on_the_same_authoritative_price(): void
    {
        [$category, $franchise, $zone, $services] = $this->scenario();

        $checkout = $this->checkoutPrices($services, $franchise, $zone);

        app(CustomerLocationContext::class)->setZone($zone->id);

        $rendered = $this->renderedOrder(
            Livewire::test(CategoryShow::class, ['category' => $category])->set('sort', 'price_low')
        );

        $this->assertSame($this->orderedByCheckoutPrice($checkout), $rendered);
    }

    /**
     * The price shown on the card and the position the card is sorted into
     * must come from the same number. Asserting the rendered order against
     * the presenter's own prices catches a sort that is internally
     * consistent with checkout but inconsistent with the page.
     */
    public function test_the_rendered_order_is_ascending_in_the_price_printed_on_each_card(): void
    {
        [, , $zone] = $this->scenario();

        app(CustomerLocationContext::class)->setZone($zone->id);

        $prices = Livewire::test(ServiceIndex::class)
            ->set('sort', 'price_low')
            ->viewData('cards')
            ->pluck('price')
            ->all();

        $sorted = $prices;
        sort($sorted);

        $this->assertSame($sorted, $prices, 'Card prices are not ascending in a price_low sort.');
        $this->assertSame([50.0, 100.0, 200.0], $prices);
    }

    // ==================== Boundaries of the override ====================

    /**
     * resolvePrice() only honours an override row with is_offered = true, and
     * checkout therefore charges the stored cascade for a not-offered row.
     * The sort must draw exactly the same line — an ordering stricter or
     * looser than checkout is the same class of defect in the other
     * direction.
     */
    public function test_a_not_offered_override_is_ignored_by_the_sort_exactly_as_checkout_ignores_it(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();

        $cheap = $this->makeService($category, ['name' => 'Alpha', 'base_price' => 100]);
        $dear = $this->makeService($category, ['name' => 'Bravo', 'base_price' => 900]);

        FranchiseServicePricing::create([
            'franchise_id' => $franchise->id,
            'service_id' => $dear->id,
            'price_override' => 10,
            'is_offered' => false, // not offered -> override must not apply
        ]);

        $checkout = $this->checkoutPrices(['Alpha' => $cheap, 'Bravo' => $dear], $franchise, $zone);
        $this->assertSame(['Alpha' => 100.0, 'Bravo' => 900.0], $checkout);

        app(CustomerLocationContext::class)->setZone($zone->id);

        $this->assertSame(
            ['Alpha', 'Bravo'],
            $this->renderedOrder(Livewire::test(ServiceIndex::class)->set('sort', 'price_low'))
        );
    }

    /**
     * Another franchise's override must never reorder this viewer's catalog.
     */
    public function test_another_franchises_override_does_not_affect_this_viewers_order(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $category = $this->makeCategory();

        $this->makeService($category, ['name' => 'Alpha', 'base_price' => 100]);
        $dear = $this->makeService($category, ['name' => 'Bravo', 'base_price' => 900]);

        FranchiseServicePricing::create([
            'franchise_id' => $otherFranchise->id,
            'service_id' => $dear->id,
            'price_override' => 10,
            'is_offered' => true,
        ]);

        app(CustomerLocationContext::class)->setZone($zone->id);

        $this->assertSame(
            ['Alpha', 'Bravo'],
            $this->renderedOrder(Livewire::test(ServiceIndex::class)->set('sort', 'price_low'))
        );
    }

    /**
     * With no zone chosen there is no franchise context, and resolvePrice(null)
     * skips the override lookup — so the sort must fall back to the stored
     * cascade, which is exactly what an anonymous visitor is quoted.
     */
    public function test_with_no_zone_chosen_the_sort_uses_the_stored_cascade(): void
    {
        [, , , $services] = $this->scenario();

        $this->assertNotEmpty($services);

        $this->assertSame(
            ['Alpha', 'Charlie', 'Bravo'],
            $this->renderedOrder(Livewire::test(ServiceIndex::class)->set('sort', 'price_low'))
        );
    }

    /** Pagination issues a separate count query; the ordering subquery must not break it. */
    public function test_price_sorting_paginates(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();

        for ($i = 1; $i <= 15; $i++) {
            $service = $this->makeService($category, [
                'name' => 'Service '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'base_price' => 1000 - $i,
            ]);

            FranchiseServicePricing::create([
                'franchise_id' => $franchise->id,
                'service_id' => $service->id,
                'price_override' => $i,
                'is_offered' => true,
            ]);
        }

        app(CustomerLocationContext::class)->setZone($zone->id);

        $paginator = Livewire::test(ServiceIndex::class)->set('sort', 'price_low')->viewData('paginator');

        $this->assertSame(15, $paginator->total());
        $this->assertSame(12, $paginator->count());
        $this->assertSame('Service 01', $paginator->getCollection()->first()->name);
    }
}
