<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\ContentPage;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * REF 1CF-SITEMAP-001 / F3 - /sitemap.xml is an index: the static map plus one map per LIVE city.
 * Only real pages are listed (see tests/Feature/Seo/F3CitySitemapTest for the indexing rules).
 */
class SitemapTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use RefreshDatabase;

    private const BASE = 'https://1callfix.com';

    public function test_index_lists_the_static_map_and_one_map_per_live_city(): void
    {
        Cache::flush();
        $this->liveCity('Nellore');

        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $xml = $response->getContent();

        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString('<loc>'.self::BASE.'/sitemap-static.xml</loc>', $xml);
        $this->assertStringContainsString('<loc>'.self::BASE.'/sitemap-nellore.xml</loc>', $xml);
    }

    public function test_static_map_lists_storefront_pages_and_published_cms_pages(): void
    {
        Cache::flush();
        ContentPage::create(['slug' => 'franchise', 'title' => 'Franchise', 'content' => 'x', 'is_active' => true]);
        ContentPage::create(['slug' => 'draft-page', 'title' => 'Draft', 'content' => 'x', 'is_active' => false]);
        ContentPage::create(['slug' => 'privacy', 'title' => 'Privacy', 'content' => 'x', 'is_active' => true]);

        $xml = $this->get('/sitemap-static.xml')->assertOk()->getContent();

        foreach (['/', '/services', '/categories', '/franchise', '/privacy'] as $path) {
            $this->assertStringContainsString('<loc>'.rtrim(self::BASE.$path, '/').($path === '/' ? '/' : '').'</loc>', $xml, $path);
        }

        $this->assertStringNotContainsString('draft-page', $xml);
        $this->assertStringNotContainsString('/admin', $xml);
        $this->assertSame(1, substr_count($xml, '<loc>'.self::BASE.'/privacy</loc>'), 'privacy listed once');
    }

    public function test_addresses_use_the_canonical_base_not_the_request_host(): void
    {
        Cache::flush();
        $this->liveCity('Nellore');

        $xml = $this->get('http://staging.example.test/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('staging.example.test', $xml);
        $this->assertStringContainsString(Seo::canonicalBase(), $xml);
    }
}
