<?php

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-23: no SEO fields for owners (Google ignores keywords; owner links are a spam risk). Every item page
 * already gets its title, description, sitemap entry and Google card automatically; the shop page was the one
 * without a card. It now sends a schema.org Store built from what the shop already filled in. Real data only, and
 * never the shop's phone.
 */
class ShopStoreSchemaTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function blocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($j) => json_decode(html_entity_decode($j), true), $m[1]);
    }

    public function test_the_shop_page_sends_googles_local_shop_card_from_what_the_shop_filled_in(): void
    {
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore', 'name' => 'Nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores', 'address_line' => '12 Trunk Road',
            'lat' => 14.4426, 'lng' => 79.9865, 'phone' => '9000000001', 'description' => 'Cotton clothes and steel bottles.']);
        $this->makeProduct($store, ['name' => 'Steel Bottle', 'price' => 200, 'stock' => 3]);
        $store->hours()->create(['day' => 1, 'opens_at' => '09:00:00', 'closes_at' => '21:00:00']);

        $html = $this->get(route('customer.shop.store', ['nellore', $store->slug]))->assertOk()->getContent();
        $shop = collect($this->blocks($html))->firstWhere('@type', 'Store');

        $this->assertNotNull($shop, 'the shop page has its Store card');
        $this->assertSame('Lakshmi Stores', $shop['name']);
        $this->assertSame(['@type' => 'PostalAddress', 'streetAddress' => '12 Trunk Road', 'addressLocality' => 'Nellore', 'addressCountry' => 'IN'], $shop['address']);
        $this->assertEqualsWithDelta(14.4426, $shop['geo']['latitude'], 0.0001);
        $this->assertSame([['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Monday', 'opens' => '09:00', 'closes' => '21:00']], $shop['openingHoursSpecification']);
        $this->assertSame('Cotton clothes and steel bottles.', $shop['description']);
        $this->assertArrayNotHasKey('aggregateRating', $shop, 'no reviews yet: no rating is invented');
        $this->assertStringNotContainsString('9000000001', json_encode($shop), 'the shop phone is never published');
    }

    public function test_a_test_shop_page_is_not_indexed_and_sends_no_card(): void
    {
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Empty Shop']); // no products = not indexable

        $html = $this->get(route('customer.shop.store', ['nellore', $store->slug]))->assertOk()->getContent();
        $this->assertStringContainsString('noindex', $html);
        $this->assertNull(collect($this->blocks($html))->firstWhere('@type', 'Store'));
    }
}
