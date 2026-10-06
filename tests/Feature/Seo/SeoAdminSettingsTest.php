<?php

namespace Tests\Feature\Seo;

use App\Livewire\Seo\CityContent;
use App\Livewire\Seo\Settings as SeoSettingsScreen;
use App\Models\ActivityLog;
use App\Models\ContentPage;
use App\Models\Setting;
use App\Services\Seo\SeoSettings;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Admin-controlled SEO: Search & social settings (site name template, default description and image, home page, per
 * module, business details, verification tags), the search preview with counters, JSON-LD, filtered-page noindex,
 * page-2 canonical, sitemap invalidation and the opt-in host redirect.
 */
class SeoAdminSettingsTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function htmlHead(string $uri = '/'): string
    {
        $html = $this->get($uri)->assertOk()->getContent();

        return substr($html, 0, (int) strpos($html, '</head>'));
    }

    // ---------------------------------------------------------------- defaults are unchanged

    public function test_a_fresh_install_renders_exactly_the_old_title_and_description(): void
    {
        $head = $this->htmlHead('/');

        $this->assertStringContainsString('<title>Home services, on call · 1CallFix</title>', $head);
        $this->assertStringContainsString('content="1CallFix — verified local professionals for repairs, installation and maintenance."', $head);
        $this->assertStringNotContainsString('google-site-verification', $head);
    }

    // ---------------------------------------------------------------- home, template, defaults

    public function test_home_title_and_description_come_from_the_settings_and_skip_the_template(): void
    {
        Setting::set(SeoSettings::HOME_TITLE, 'Home Services in Nellore | 1CallFix');
        Setting::set(SeoSettings::HOME_DESCRIPTION, 'Book verified AC, electrical and plumbing professionals in Nellore.');

        $head = $this->htmlHead('/');

        $this->assertStringContainsString('<title>Home Services in Nellore | 1CallFix</title>', $head);
        $this->assertStringContainsString('content="Book verified AC, electrical and plumbing professionals in Nellore."', $head);
        $this->assertStringContainsString('<meta property="og:title" content="Home Services in Nellore | 1CallFix">', $head);
    }

    public function test_the_title_template_applies_to_other_pages(): void
    {
        Setting::set(SeoSettings::TITLE_TEMPLATE, '{page} | {site}');

        $this->assertStringContainsString('<title>How it works | 1CallFix</title>', $this->htmlHead('/how-it-works'));
    }

    public function test_a_template_without_the_page_token_is_ignored_so_titles_never_vanish(): void
    {
        Setting::set(SeoSettings::TITLE_TEMPLATE, 'Just the site');

        $this->assertStringContainsString('<title>How it works · 1CallFix</title>', $this->htmlHead('/how-it-works'));
    }

    public function test_the_default_description_fills_pages_that_have_none(): void
    {
        Setting::set(SeoSettings::DEFAULT_DESCRIPTION, 'Trusted home professionals across Andhra Pradesh.');

        $this->assertStringContainsString('content="Trusted home professionals across Andhra Pradesh."', $this->htmlHead('/how-it-works'));
    }

    public function test_the_default_share_image_is_absolute_on_the_canonical_host(): void
    {
        Setting::set(SeoSettings::DEFAULT_IMAGE, '/storage/seo/share.png');

        $this->assertStringContainsString('<meta property="og:image" content="https://1callfix.com/storage/seo/share.png">', $this->htmlHead('/'));
        $this->assertSame('https://1callfix.com/storage/a.png', Seo::absoluteUrl(request()->getSchemeAndHttpHost().'/storage/a.png'));
        $this->assertSame('https://cdn.example.org/x.png', Seo::absoluteUrl('https://cdn.example.org/x.png'));
    }

    public function test_verification_tags_render_only_when_set(): void
    {
        Setting::set(SeoSettings::VERIFY_GOOGLE, 'abc123_-XYZ');
        Setting::set(SeoSettings::VERIFY_BING, 'BING-TOKEN-1');

        $head = $this->htmlHead('/');

        $this->assertStringContainsString('<meta name="google-site-verification" content="abc123_-XYZ">', $head);
        $this->assertStringContainsString('<meta name="msvalidate.01" content="BING-TOKEN-1">', $head);
    }

    // ---------------------------------------------------------------- JSON-LD

    private function schemas(): array
    {
        $html = $this->get('/')->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
    }

    public function test_home_has_organization_and_website_json_ld_but_no_local_business_until_details_are_complete(): void
    {
        $types = array_column($this->schemas(), '@type');

        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertNotContains('LocalBusiness', $types);
    }

    public function test_local_business_appears_once_the_business_details_are_complete(): void
    {
        foreach (['legal_name' => '1CallFix Services', 'phone' => '+919876543210', 'street' => '12 Main Road', 'city' => 'Nellore', 'region' => 'Andhra Pradesh', 'postal_code' => '524001'] as $k => $v) {
            Setting::set("seo.business.{$k}", $v);
        }

        $local = collect($this->schemas())->firstWhere('@type', 'LocalBusiness');

        $this->assertSame('Nellore', $local['address']['addressLocality']);
        $this->assertSame('IN', $local['address']['addressCountry']);
        $this->assertSame('+919876543210', $local['telephone']);
    }

    public function test_a_hostile_business_name_cannot_break_out_of_the_script_tag(): void
    {
        Setting::set('seo.business.legal_name', '</script><script>alert(1)</script>');

        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertNotEmpty($this->schemas());
    }

    public function test_other_pages_carry_no_json_ld(): void
    {
        $this->assertSame(0, substr_count($this->get('/how-it-works')->getContent(), 'application/ld+json'));
    }

    // ---------------------------------------------------------------- filtered pages and pagination

    public function test_filtered_and_searched_catalog_pages_are_noindex_and_the_plain_page_is_not(): void
    {
        $this->assertStringContainsString('content="index, follow"', $this->htmlHead('/services'));
        $this->assertStringContainsString('content="noindex, nofollow"', $this->htmlHead('/services?q=fan'));
        $this->assertStringContainsString('content="noindex, nofollow"', $this->htmlHead('/services?sort=price_low'));
    }

    public function test_page_two_canonicalises_to_itself_and_tracking_params_are_still_dropped(): void
    {
        $this->assertStringContainsString('<link rel="canonical" href="https://1callfix.com/services?page=2">', $this->htmlHead('/services?page=2&utm_source=x&fbclid=y'));
        $this->assertStringContainsString('<link rel="canonical" href="https://1callfix.com/services">', $this->htmlHead('/services?page=1&gclid=z'));
        $this->assertStringContainsString('<link rel="canonical" href="https://1callfix.com/services">', $this->htmlHead('/services?page=abc'));
    }

    public function test_module_seo_overrides_the_services_listing_copy(): void
    {
        Setting::set(SeoSettings::MODULES, json_encode(['service' => ['title' => 'Home Services', 'description' => 'Every home service in one place.']]));

        $head = $this->htmlHead('/services');

        $this->assertStringContainsString('<title>Home Services · 1CallFix</title>', $head);
        $this->assertStringContainsString('content="Every home service in one place."', $head);
    }

    // ---------------------------------------------------------------- sitemap invalidation

    public function test_saving_content_busts_the_sitemap_cache_without_waiting_for_the_ttl(): void
    {
        $this->get('/sitemap-static.xml')->assertOk();
        $this->assertStringNotContainsString('/about-us', $this->get('/sitemap-static.xml')->getContent());

        ContentPage::create(['slug' => 'about-us', 'title' => 'About us', 'content' => 'Hello', 'is_active' => true]);

        $this->assertStringContainsString('/about-us', $this->get('/sitemap-static.xml')->getContent());
    }

    public function test_seo_settings_changes_also_bump_the_sitemap_version(): void
    {
        $before = Seo::sitemapVersion();

        Setting::set(Seo::SETTING_KEY, 'https://1callfix.com');

        $this->assertGreaterThan($before, Seo::sitemapVersion());
        Cache::forget('seo:sitemap_version');
    }

    // ---------------------------------------------------------------- host redirect (opt-in)

    public function test_the_host_redirect_is_off_by_default(): void
    {
        $this->get('http://www.1callfix.com/services')->assertOk();
    }

    public function test_when_enabled_non_canonical_hosts_and_http_redirect_with_the_query_kept(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '1');

        $this->get('http://www.1callfix.com/services?utm_source=x')->assertStatus(301)->assertRedirect('https://1callfix.com/services?utm_source=x');
        $this->get('http://1callfix.com/help')->assertStatus(301)->assertRedirect('https://1callfix.com/help');
        $this->get('https://api.1callfix.com/services')->assertStatus(301)->assertRedirect('https://1callfix.com/services');
        $this->get('https://1callfix.com/services')->assertOk();
    }

    public function test_the_redirect_never_touches_the_api_health_posts_or_assets(): void
    {
        Setting::set(SeoSettings::REDIRECT_HOST, '1');

        $this->get('https://api.1callfix.com/api/services')->assertOk();
        $this->get('https://www.1callfix.com/up')->assertOk();
        $this->post('https://www.1callfix.com/api/webhooks/razorpay', [])->assertStatus(400);
    }

    // ---------------------------------------------------------------- admin screen

    private function superAdmin()
    {
        return $this->makeSuperAdmin();
    }

    public function test_only_a_super_admin_can_open_or_save_the_screen(): void
    {
        $editor = $this->makeUserWithPermission('seo.edit_city_content', 'global');

        Livewire::actingAs($editor)->test(SeoSettingsScreen::class)->assertForbidden();
        $this->actingAs($editor)->get(route('admin.seo.settings'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('admin.seo.settings'))->assertOk()->assertSee('Search &amp; social', false);
    }

    public function test_saving_stores_every_value_and_audit_logs_each_change(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)->test(SeoSettingsScreen::class)
            ->set('titleTemplate', '{page} | {site}')
            ->set('defaultDescription', 'Trusted home professionals.')
            ->set('defaultImageUrl', 'https://cdn.example.org/share.png')
            ->set('homeTitle', 'Home Services in Nellore | 1CallFix')
            ->set('homeDescription', 'Book verified professionals in Nellore.')
            ->set('business.legal_name', '1CallFix Services')
            ->set('business.phone', '+91 98765 43210')
            ->set('verifyGoogle', 'abc123_-XYZ')
            ->set('moduleTitles.service', 'Home Services')
            ->set('moduleDescriptions.service', 'Every home service.')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('{page} | {site}', Setting::get(SeoSettings::TITLE_TEMPLATE));
        $this->assertSame('Home Services in Nellore | 1CallFix', SeoSettings::homeTitle());
        $this->assertSame('+91 98765 43210', SeoSettings::business()['phone']);
        $this->assertSame('Home Services', SeoSettings::moduleMeta('service')['title']);
        $this->assertSame('abc123_-XYZ', SeoSettings::verification()['google']);
        $this->assertGreaterThanOrEqual(9, ActivityLog::where('subject_type', 'setting')->where('causer_id', $admin->id)->count());
    }

    public function test_invalid_values_are_rejected_and_nothing_is_stored(): void
    {
        Livewire::actingAs($this->superAdmin())->test(SeoSettingsScreen::class)
            ->set('titleTemplate', 'No page token here')
            ->set('defaultDescription', str_repeat('x', 400))
            ->set('defaultImageUrl', 'javascript:alert(1)')
            ->set('verifyGoogle', '"><script>')
            ->set('business.phone', 'call me maybe')
            ->set('business.email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['titleTemplate', 'defaultDescription', 'defaultImageUrl', 'verifyGoogle', 'business.phone', 'business.email']);

        $this->assertNull(Setting::get(SeoSettings::TITLE_TEMPLATE));
        $this->assertNull(Setting::get(SeoSettings::VERIFY_GOOGLE));
    }

    public function test_blank_fields_clear_back_to_the_fallback(): void
    {
        Setting::set(SeoSettings::HOME_TITLE, 'Old title');

        Livewire::actingAs($this->superAdmin())->test(SeoSettingsScreen::class)->set('homeTitle', '')->call('save')->assertHasNoErrors();

        $this->assertNull(SeoSettings::homeTitle());
        $this->assertStringContainsString('<title>Home services, on call · 1CallFix</title>', $this->htmlHead('/'));
    }

    public function test_the_screen_lists_every_registered_module_and_shows_the_preview_with_counters(): void
    {
        \App\Models\Module::query()->updateOrCreate(['code' => 'parcel'], ['name' => 'Parcel delivery', 'sort_order' => 5, 'is_active' => false, 'is_implemented' => true]);

        Livewire::actingAs($this->superAdmin())->test(SeoSettingsScreen::class)
            ->set('homeTitle', 'Home Services in Nellore | 1CallFix')
            ->assertSee('Parcel delivery')
            ->assertSeeHtml('data-testid="seo-preview"')
            ->assertSeeHtml('Title 35/60');
    }

    public function test_the_city_content_screen_shows_the_preview_and_counters_too(): void
    {
        $editor = $this->makeSuperAdmin();
        $city = \App\Models\City::query()->first() ?? $this->makeCity();

        Livewire::actingAs($editor)->test(CityContent::class)
            ->set('cityId', $city->id)->set('title', 'Home services in Nellore')
            ->assertSeeHtml('data-testid="seo-preview"');
    }
}
