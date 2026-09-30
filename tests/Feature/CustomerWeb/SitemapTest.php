<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-SITEMAP-001 — /sitemap.xml lists the public pages and only live content.
 */
class SitemapTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    public function test_sitemap_lists_storefront_pages_active_services_categories_and_published_cms_pages(): void
    {
        Cache::flush();
        [$category, $service] = $this->makeCategoryAndService();
        ContentPage::create(['slug' => 'franchise', 'title' => 'Franchise', 'content' => 'x', 'is_active' => true]);
        ContentPage::create(['slug' => 'draft-page', 'title' => 'Draft', 'content' => 'x', 'is_active' => false]);
        ContentPage::create(['slug' => 'privacy', 'title' => 'Privacy', 'content' => 'x', 'is_active' => true]);

        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $xml = $response->getContent();

        foreach ([route('customer.home'), route('customer.services.index'), route('customer.categories.index'),
            route('customer.categories.show', $category), route('customer.services.show', $service),
            url('/franchise'), route('customer.privacy')] as $expected) {
            $this->assertStringContainsString('<loc>'.e($expected).'</loc>', $xml, $expected);
        }

        $this->assertStringNotContainsString('draft-page', $xml);
        $this->assertStringNotContainsString('/admin', $xml);
        $this->assertSame(1, substr_count($xml, '<loc>'.e(route('customer.privacy')).'</loc>'), 'privacy listed once');
        $this->assertStringNotContainsString(url('/privacy').'</loc><lastmod>', $xml);
    }

    public function test_inactive_service_is_left_out(): void
    {
        Cache::flush();
        [, $service] = $this->makeCategoryAndService();
        $service->update(['is_active' => false]);

        $this->assertStringNotContainsString(route('customer.services.show', $service), $this->get('/sitemap.xml')->getContent());
    }
}
