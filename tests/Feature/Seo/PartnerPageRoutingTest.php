<?php

namespace Tests\Feature\Seo;

use App\Models\City;
use App\Models\ContentPage;
use App\Models\Setting;
use App\Services\Slug\SlugManager;
use App\Support\PartnerPage\PartnerPageSettings as P;
use App\Support\Seo\ReservedSlugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001, B4: /partners, the 301s into it, SEO fields, sitemap, and no collision with city slugs. */
class PartnerPageRoutingTest extends TestCase
{
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    private const LEGACY = ['/coming-soon/partners', '/join', '/become-a-partner', '/work-with-us'];

    public function test_partners_is_served_at_the_clean_url(): void
    {
        $this->get('/partners')->assertOk()->assertSeeText('Pick your role');
        $this->assertSame('/partners', route('customer.partners', [], false));
    }

    public function test_every_older_address_permanently_redirects_keeping_the_query_string(): void
    {
        foreach (self::LEGACY as $path) {
            $this->get($path)->assertStatus(301)->assertRedirect(url('/partners'));
            $this->get($path.'?utm_source=google&utm_campaign=jobs&gclid=abc')
                ->assertStatus(301)->assertRedirect(url('/partners').'?utm_source=google&utm_campaign=jobs&gclid=abc');
        }
    }

    public function test_title_description_and_canonical_come_from_the_seo_fields(): void
    {
        Setting::set(P::key('seo.title'), 'Become a 1CallFix partner');
        Setting::set(P::key('seo.description'), 'Apply to take jobs near you.');

        $html = $this->get('/partners?utm_source=google')->assertOk()->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        $this->assertStringContainsString('<title>Become a 1CallFix partner · 1CallFix</title>', $head);
        $this->assertStringContainsString('content="Apply to take jobs near you."', $head);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $head);
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"?]+/partners">#', $head);
    }

    public function test_the_sitemap_lists_the_new_url_and_not_the_old_one(): void
    {
        $xml = $this->get('/sitemap-static.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/partners</loc>', $xml);
        $this->assertStringNotContainsString('coming-soon/partners', $xml);
    }

    public function test_no_city_category_or_service_can_take_a_partner_address(): void
    {
        foreach (['partners', 'join', 'become-a-partner', 'work-with-us', 'coming-soon'] as $word) {
            $this->assertTrue(ReservedSlugs::isReserved($word), $word);
            $this->assertNotNull(SlugManager::problem(new City, $word), $word);
        }

        $this->assertNotSame('join', SlugManager::generate(new City, 'Join'));
        $this->assertNotSame('partners', SlugManager::generate(new City, 'Partners'));
    }

    public function test_even_a_city_row_with_a_colliding_slug_cannot_shadow_the_page_or_the_redirects(): void
    {
        foreach (['partners', 'join', 'work-with-us'] as $slug) {
            $city = $this->makeCity();
            City::whereKey($city->id)->update(['slug' => $slug]);
        }
        ContentPage::create(['slug' => 'become-a-partner', 'title' => 'CMS', 'content' => 'x', 'is_active' => true]);

        $this->get('/partners')->assertOk()->assertSeeText('Pick your role');
        $this->get('/join')->assertStatus(301)->assertRedirect(url('/partners'));
        $this->get('/work-with-us?x=1')->assertStatus(301)->assertRedirect(url('/partners').'?x=1');
        $this->get('/become-a-partner')->assertStatus(301)->assertRedirect(url('/partners'));
    }

    public function test_other_coming_soon_pages_and_real_cities_are_untouched(): void
    {
        $this->get('/coming-soon/membership')->assertOk();

        $this->liveCity('Nellore');
        $this->get('/nellore')->assertOk();
    }
}
