<?php

namespace Tests\Feature\CustomerWeb;

use App\Services\Seo\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structured data for service pages: real price and breadcrumb always, an AggregateRating only from real reviews.
 */
class ServiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_describes_the_service_with_its_real_price_and_breadcrumb(): void
    {
        [$service, $crumbs] = SeoSettings::serviceSchema('Split AC Service', 'Full service', null, 'https://1callfix.com/nellore/split-ac-service', 499.0, null);

        $this->assertSame('Service', $service['@type']);
        $this->assertSame('499.00', $service['offers']['price']);
        $this->assertSame('INR', $service['offers']['priceCurrency']);
        $this->assertArrayNotHasKey('aggregateRating', $service);
        $this->assertSame('BreadcrumbList', $crumbs['@type']);
        $this->assertSame('Split AC Service', $crumbs['itemListElement'][2]['name']);
    }

    public function test_a_rating_appears_only_when_there_are_real_reviews(): void
    {
        [$none] = SeoSettings::serviceSchema('X', null, null, 'https://1callfix.com/x', 100.0, ['average' => 0.0, 'count' => 0]);
        $this->assertArrayNotHasKey('aggregateRating', $none);

        [$rated] = SeoSettings::serviceSchema('X', null, null, 'https://1callfix.com/x', 100.0, ['average' => 4.66, 'count' => 12]);
        $this->assertSame(4.7, $rated['aggregateRating']['ratingValue']);
        $this->assertSame(12, $rated['aggregateRating']['reviewCount']);
    }

    public function test_a_zero_price_publishes_no_offer(): void
    {
        [$service] = SeoSettings::serviceSchema('Free quote', null, null, 'https://1callfix.com/q', 0.0, null);

        $this->assertArrayNotHasKey('offers', $service);
    }
}
