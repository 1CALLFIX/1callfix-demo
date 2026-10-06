<?php

namespace Tests\Feature\Seo;

use App\Models\Setting;
use App\Services\Seo\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Pre-deploy proof for the SEO build: (1) the host-redirect middleware does nothing while the checkbox is off, and
 * with it on never touches the API, the Razorpay webhook, Livewire, the health check, uploads or any non-GET request;
 * (2) the robots and canonical output for every URL shape — clean-slug pages are indexable with a self canonical,
 * only query variants are noindex.
 */
class RedirectAndIndexingSafetyTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use LiveCity;
    use RefreshDatabase;

    private const BASE = 'https://1callfix.com';

    /** Every host variant that is NOT the canonical origin. */
    private const OFF_HOSTS = ['http://1callfix.com', 'http://www.1callfix.com', 'https://www.1callfix.com', 'http://api.1callfix.com', 'https://api.1callfix.com'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        Setting::set('seo.min_providers_to_index', '0');
    }

    private function robotsAndCanonical(string $uri): array
    {
        $html = $this->get($uri)->assertOk()->getContent();
        preg_match('#<meta name="robots" content="([^"]+)">#', $html, $r);
        preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $c);

        return [$r[1] ?? null, $c[1] ?? null];
    }

    // ================================================================ redirect: OFF

    public function test_with_the_checkbox_off_the_middleware_does_nothing_on_any_host_or_path(): void
    {
        $this->assertNull(Setting::get(SeoSettings::REDIRECT_HOST));

        foreach (self::OFF_HOSTS as $host) {
            foreach (['/', '/services', '/help', '/nellore', '/up', '/api/services', '/sitemap.xml', '/robots.txt'] as $path) {
                $status = $this->get($host.$path)->getStatusCode();
                $this->assertNotContains($status, [301, 302, 307, 308], "{$host}{$path} must not redirect while the checkbox is off (got {$status})");
            }
        }
    }

    public function test_an_explicit_off_value_also_does_nothing(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '0');

        $this->get('http://www.1callfix.com/services')->assertOk();
    }

    // ================================================================ redirect: ON

    public function test_when_on_these_public_get_paths_redirect_to_the_canonical_origin_with_the_query_kept(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '1');

        $this->get('http://www.1callfix.com/services?q=ac&utm_source=x')->assertStatus(301)->assertRedirect(self::BASE.'/services?q=ac&utm_source=x');
        $this->get('https://api.1callfix.com/nellore')->assertStatus(301)->assertRedirect(self::BASE.'/nellore');
        $this->get('http://1callfix.com/help')->assertStatus(301)->assertRedirect(self::BASE.'/help');
        $this->get('https://1callfix.com/services')->assertOk();
    }

    public function test_when_on_the_api_the_razorpay_webhook_livewire_health_and_uploads_are_never_redirected(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '1');

        foreach (self::OFF_HOSTS as $host) {
            foreach ([
                '/api/services', '/api/v1/anything', '/api/webhooks/razorpay', '/up',
                '/livewire/livewire.js', '/livewire/update', '/livewire-8707601f/update', '/storage/branding/logo.png',
            ] as $path) {
                $status = $this->get($host.$path)->getStatusCode();
                $this->assertNotSame(301, $status, "GET {$host}{$path} must never be redirected by the host middleware");
            }
        }
    }

    public function test_when_on_any_post_put_patch_delete_or_options_is_never_redirected(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '1');

        foreach (['post', 'put', 'patch', 'delete', 'options'] as $method) {
            foreach (['https://www.1callfix.com/api/webhooks/razorpay', 'https://api.1callfix.com/admin/login', 'http://www.1callfix.com/login', 'https://api.1callfix.com/livewire/update'] as $url) {
                $status = $this->call(strtoupper($method), $url)->getStatusCode();
                $this->assertNotSame(301, $status, strtoupper($method)." {$url}");
            }
        }

        // The Razorpay webhook keeps answering as a webhook (bad signature), not as a redirect.
        $this->post('https://www.1callfix.com/api/webhooks/razorpay', [])->assertStatus(400);
    }

    public function test_when_on_admin_and_provider_gets_follow_the_canonical_origin_so_a_session_always_lives_on_one_host(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '1');

        $this->get('https://www.1callfix.com/admin/login')->assertStatus(301)->assertRedirect(self::BASE.'/admin/login');
        $this->get('https://api.1callfix.com/provider/login')->assertStatus(301)->assertRedirect(self::BASE.'/provider/login');
        $this->get(self::BASE.'/admin/login')->assertOk();
    }

    public function test_when_on_old_glover_web_paths_are_only_redirected_as_ordinary_get_pages_and_the_glover_mobile_api_is_untouched(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '1');

        // Glover's mobile app calls /api/* on 1callfix.com: those are excluded outright, on every host.
        foreach (['/api/login', '/api/v1/customer/home', '/api/vendor/list'] as $path) {
            foreach (self::OFF_HOSTS as $host) {
                $this->assertNotSame(301, $this->get($host.$path)->getStatusCode(), $host.$path);
            }
        }

        // Its web pages are ordinary GETs: on the apex they are left alone, elsewhere they only gain the canonical host.
        $this->get(self::BASE.'/central/register/vendor')->assertStatus(404);
        $this->get('https://www.1callfix.com/central/register/vendor')->assertStatus(301)->assertRedirect(self::BASE.'/central/register/vendor');
    }

    public function test_the_excluded_path_list_is_exactly_what_the_middleware_source_says(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/RedirectToCanonicalHost.php'));

        $this->assertStringContainsString("['GET', 'HEAD']", $source);
        $this->assertStringContainsString("'api', 'api/*', 'up', 'livewire', 'livewire/*', 'livewire-*', 'storage/*'", $source);
    }

    // ================================================================ robots + canonical matrix

    public function test_clean_slug_pages_are_indexable_with_a_self_canonical_and_only_query_variants_are_noindex(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category);
        $slug = '/nellore/'.$category->slug;
        $item = '/nellore/'.$service->slug;

        $matrix = [
            // uri => [robots, canonical]. A noindex variant deliberately emits NO canonical (noindex + canonical send mixed signals).
            '/' => ['index, follow', self::BASE.'/'],
            '/nellore' => ['index, follow', self::BASE.'/nellore'],
            $slug => ['index, follow', self::BASE.$slug],
            $item => ['index, follow', self::BASE.$item],
            '/services' => ['index, follow', self::BASE.'/services'],
            '/services?q=ac' => ['noindex, nofollow', null],
            '/services?sort=price_low' => ['noindex, nofollow', null],
            '/services?page=2' => ['index, follow', self::BASE.'/services?page=2'],
            '/services?category='.$category->id => ['noindex, nofollow', null],
            $slug.'?q=ac' => ['noindex, nofollow', null],
            $slug.'?sort=price_low' => ['noindex, nofollow', null],
            $slug.'?page=2' => ['index, follow', self::BASE.$slug.'?page=2'],
            $slug.'?utm_source=g&gclid=1' => ['index, follow', self::BASE.$slug],
        ];

        $printed = [];
        foreach ($matrix as $uri => [$robots, $canonical]) {
            [$gotRobots, $gotCanonical] = $this->robotsAndCanonical($uri);
            $printed[] = sprintf('%-46s %-18s %s', $uri, $gotRobots, $gotCanonical);

            $this->assertSame($robots, $gotRobots, "robots for {$uri}");
            $this->assertSame($canonical, $gotCanonical, "canonical for {$uri}");
        }

        fwrite(STDERR, "\nINDEXING MATRIX\n".implode("\n", $printed)."\n");
    }

    public function test_a_category_is_reachable_by_its_clean_slug_and_the_filter_url_is_only_a_filter_on_the_list(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category);

        // Clean slug page exists and is indexable.
        $this->get('/nellore/'.$category->slug)->assertOk();
        // The filter URL is the same list narrowed, never the only address of the category.
        $this->get('/services?category='.$category->id)->assertOk();
        $this->assertStringContainsString('/nellore/'.$category->slug, $this->get('/categories')->getContent());
    }
}
