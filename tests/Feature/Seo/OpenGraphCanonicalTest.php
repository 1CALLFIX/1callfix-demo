<?php

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\TestCase;

/** F2: canonical + Open Graph/Twitter tags on the customer layout. */
class OpenGraphCanonicalTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_home_has_canonical_without_query_string_and_og_tags(): void
    {
        $html = $this->get('/?utm_source=google&gclid=abc&page=2')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringNotContainsString('rel="canonical" href="'.url('/').'?', $html);
        $this->assertStringContainsString('<meta property="og:title"', $html);
        $this->assertStringContainsString('<meta property="og:description"', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.url('/').'">', $html);
        $this->assertStringContainsString('<meta name="twitter:card"', $html);
    }

    public function test_service_page_uses_its_cover_image_and_its_own_url(): void
    {
        $service = $this->makeService($this->makeCategory(), [
            'name' => 'AC Repair',
            'cover_image' => 'https://cdn.example.com/ac.jpg',
        ]);

        $html = $this->get(route('customer.services.show', $service).'?utm_campaign=x')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example.com/ac.jpg">', $html);
        $this->assertStringContainsString('content="summary_large_image"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('customer.services.show', $service).'">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="AC Repair">', $html);
    }

    public function test_relative_og_image_is_made_absolute(): void
    {
        $service = $this->makeService($this->makeCategory(), ['cover_image' => 'services/x.jpg']);

        $html = $this->get(route('customer.services.show', $service))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https?://[^"]+services/x\.jpg">#', $html);
    }
}
