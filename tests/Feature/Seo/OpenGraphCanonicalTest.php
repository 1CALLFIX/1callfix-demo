<?php

namespace Tests\Feature\Seo;

use App\Livewire\Settings\Manage as SettingsManage;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** F2: canonical + Open Graph (public pages) and noindex (private pages). */
class OpenGraphCanonicalTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use LiveCity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        // F3: index only real pages. These tests are about tags, not coverage: index every live page.
        Setting::set('seo.min_providers_to_index', '0');
    }

    private const BASE = 'https://1callfix.com';

    private function admin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Super Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    public function test_home_canonical_uses_the_default_base_not_the_request_host_and_drops_the_query(): void
    {
        $html = $this->get('http://www.example.test/?utm_source=google&gclid=abc&page=2')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.self::BASE.'/">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.self::BASE.'/">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<meta property="og:title"', $html);
        $this->assertStringContainsString('<meta name="twitter:card"', $html);
    }

    public function test_canonical_follows_the_setting_regardless_of_request_host(): void
    {
        Setting::set('seo.canonical_base_url', 'https://example.org');
        $service = $this->makeService($this->makeCategory(), ['name' => 'AC Repair']);
        $path = '/nellore/'.$service->slug;

        foreach (['http://www.1callfix.com', 'https://api.1callfix.com', 'http://127.0.0.1:8000'] as $host) {
            $html = $this->get($host.$path.'?utm=1')->assertOk()->getContent();
            $this->assertStringContainsString('<link rel="canonical" href="https://example.org'.$path.'">', $html, $host);
            $this->assertStringContainsString('<meta property="og:url" content="https://example.org'.$path.'">', $html, $host);
        }
    }

    public function test_service_page_uses_its_cover_image(): void
    {
        $service = $this->makeService($this->makeCategory(), ['cover_image' => 'https://cdn.example.com/ac.jpg']);

        $html = $this->get(\App\Support\Seo\PublicUrl::service($service))->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example.com/ac.jpg">', $html);
        $this->assertStringContainsString('content="summary_large_image"', $html);
    }

    public function test_relative_og_image_is_made_absolute(): void
    {
        $service = $this->makeService($this->makeCategory(), ['cover_image' => 'services/x.jpg']);

        $html = $this->get(\App\Support\Seo\PublicUrl::service($service))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https?://[^"]+services/x\.jpg">#', $html);
    }

    public function test_public_pages_are_indexable_with_a_canonical(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category);

        foreach ([
            '/', '/categories', '/nellore', '/nellore/'.$category->slug, '/services', '/nellore/'.$service->slug,
            '/help', '/how-it-works', '/coming-soon/partners',
        ] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html, $path);
            $this->assertStringContainsString('<link rel="canonical"', $html, $path);
            $this->assertStringNotContainsString('noindex', $html, $path);
        }
    }

    public function test_private_pages_are_noindex_with_no_canonical(): void
    {
        $service = $this->makeService($this->makeCategory());

        foreach (['/login', '/signup', '/admin/login', '/provider/login', '/provider/register'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->status(), $path);
            $html = $response->getContent();
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html, $path);
            $this->assertStringNotContainsString('rel="canonical"', $html, $path);
            $this->assertStringNotContainsString('og:url', $html, $path);
        }

        foreach (['earnings.enabled', 'earnings.wallet_tab', 'earnings.loyalty_tab', 'earnings.referral_tab'] as $key) {
            Setting::set($key, '1');
        }
        $this->actingAs($this->makeCustomer());
        foreach ([
            '/cart', '/checkout', '/orders', '/account', '/earnings/wallet', '/earnings/loyalty',
            '/earnings/referrals', '/book/'.$service->getRouteKey(),
        ] as $path) {
            $response = $this->followingRedirects()->get($path);
            $this->assertSame(200, $response->status(), $path);
            $html = $response->getContent();
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html, $path);
            $this->assertStringNotContainsString('rel="canonical"', $html, $path);
            $this->assertStringNotContainsString('index, follow', $html, $path);
        }
    }

    public function test_admin_pages_are_noindex(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/account')->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_super_admin_can_set_the_base_and_it_is_audit_logged(): void
    {
        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->assertSet('seoCanonicalBaseUrl', self::BASE)
            ->set('seoCanonicalBaseUrl', 'https://www.example.org/')
            ->call('saveSiteLinks')->assertHasNoErrors();

        $this->assertSame('https://www.example.org', Setting::get('seo.canonical_base_url'));
        $this->assertTrue(ActivityLog::where('description', 'Setting seo.canonical_base_url changed')->exists());
    }

    public function test_base_must_be_an_https_origin(): void
    {
        foreach (['http://example.org', 'https://example.org/path', 'javascript:alert(1)'] as $bad) {
            Livewire::actingAs($this->admin())->test(SettingsManage::class)
                ->set('seoCanonicalBaseUrl', $bad)->call('saveSiteLinks')->assertHasErrors('seoCanonicalBaseUrl');
        }
    }

    public function test_blank_setting_falls_back_to_the_default(): void
    {
        Setting::set('seo.canonical_base_url', '');

        $this->assertStringContainsString('<link rel="canonical" href="'.self::BASE.'/">', $this->get('/')->getContent());
    }
}
