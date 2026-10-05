<?php

namespace Tests\Feature\Seo;

use App\Models\Franchise;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * F3 - index only real pages, and list only those in the per-city sitemap.
 * Real = the city has at least seo.min_providers_to_index approved providers for that category (default 1).
 */
class F3SitemapIndexingTest extends TestCase
{
    use CatalogFixtures;
    use LiveCity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_default_minimum_is_one_provider(): void
    {
        $this->assertSame(1, \App\Services\Seo\ProviderCoverage::minToIndex());
    }

    public function test_a_page_with_no_provider_is_noindex_and_not_in_the_sitemap(): void
    {
        $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $service = $this->makeService($category, ['name' => 'Gas Refill', 'slug' => null]);

        foreach (['/nellore', '/nellore/ac-repair', '/nellore/gas-refill'] as $path) {
            $this->get($path)->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        }

        $xml = $this->get('/sitemap-nellore.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/nellore/ac-repair', $xml);
        $this->assertStringNotContainsString('/nellore/gas-refill', $xml);
        $this->assertStringNotContainsString('<loc>https://1callfix.com/nellore</loc>', $xml);
        $this->assertNotNull($service);
    }

    public function test_a_real_page_is_indexable_with_a_canonical_and_listed(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $this->makeService($category, ['name' => 'Gas Refill', 'slug' => null]);
        $this->providerFor($city, [$category->id]);

        foreach (['/nellore', '/nellore/ac-repair', '/nellore/gas-refill'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html, $path);
            $this->assertStringContainsString('<link rel="canonical" href="https://1callfix.com'.$path.'">', $html, $path);
        }

        $xml = $this->get('/sitemap-nellore.xml')->assertOk()->getContent();
        foreach (['/nellore', '/nellore/ac-repair', '/nellore/gas-refill'] as $path) {
            $this->assertStringContainsString('<loc>https://1callfix.com'.$path.'</loc>', $xml, $path);
        }
    }

    public function test_coverage_is_per_category(): void
    {
        $city = $this->liveCity('Nellore');
        $covered = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $bare = $this->makeCategory(['name' => 'Plumbing', 'slug' => null]);
        $this->providerFor($city, [$covered->id]);

        $this->get('/nellore/ac-repair')->assertSee('index, follow', false);
        $this->get('/nellore/plumbing')->assertSee('noindex', false);

        $xml = $this->get('/sitemap-nellore.xml')->getContent();
        $this->assertStringContainsString('/nellore/ac-repair', $xml);
        $this->assertStringNotContainsString('/nellore/plumbing', $xml);
        $this->assertNotNull($bare);
    }

    public function test_the_minimum_is_a_setting_and_unapproved_or_inactive_providers_do_not_count(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $provider = $this->providerFor($city, [$category->id]);

        Setting::set('seo.min_providers_to_index', '2');
        $this->get('/nellore/ac-repair')->assertSee('noindex', false);

        $this->providerFor($city, [$category->id])->update(['kyc_status' => 'pending']);
        $this->get('/nellore/ac-repair')->assertSee('noindex', false);

        $this->providerFor($city, [$category->id])->update(['is_active' => false]);
        $this->get('/nellore/ac-repair')->assertSee('noindex', false);

        $this->providerFor($city, [$category->id]);
        $this->get('/nellore/ac-repair')->assertSee('index, follow', false);

        Setting::set('seo.min_providers_to_index', '0');
        $this->assertNotNull($provider);
    }

    public function test_inactive_city_and_inactive_items_are_not_in_any_sitemap(): void
    {
        $nellore = $this->liveCity('Nellore');
        $guntur = $this->liveCity('Guntur');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $off = $this->makeService($category, ['name' => 'Off', 'slug' => null, 'is_active' => false]);
        $this->providerFor($nellore, [$category->id]);
        $this->providerFor($guntur, [$category->id]);
        $guntur->update(['is_active' => false]);

        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('sitemap-nellore.xml', $index);
        $this->assertStringContainsString('sitemap-static.xml', $index);
        $this->assertStringNotContainsString('guntur', $index);

        $this->get('/sitemap-guntur.xml')->assertNotFound();
        $this->get('/sitemap-nobody.xml')->assertNotFound();
        $this->assertStringNotContainsString('/nellore/off', $this->get('/sitemap-nellore.xml')->getContent());
        $this->assertNotNull($off);
    }

    public function test_a_city_whose_franchise_is_inactive_is_not_listed(): void
    {
        $city = $this->liveCity('Kurnool');
        Franchise::where('city_id', $city->id)->update(['status' => 'inactive']);

        $this->assertStringNotContainsString('kurnool', $this->get('/sitemap.xml')->getContent());
        $this->get('/sitemap-kurnool.xml')->assertNotFound();
    }

    public function test_sitemap_addresses_use_the_canonical_base_setting(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $this->providerFor($city, [$category->id]);
        Setting::set('seo.canonical_base_url', 'https://example.org');

        $this->assertStringContainsString('<loc>https://example.org/nellore/ac-repair</loc>', $this->get('/sitemap-nellore.xml')->getContent());
        $this->assertStringContainsString('<loc>https://example.org/sitemap-nellore.xml</loc>', $this->get('/sitemap.xml')->getContent());
    }
}
