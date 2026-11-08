<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\Setting;
use App\Support\HomeLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner 2026-11-08: a proper "Get the app" on the website — homepage section, /app page, footer badge, QR link. */
class GetAppSectionTest extends TestCase
{
    use LiveCity;
    use RefreshDatabase;

    private const PLAY = 'https://play.google.com/store/apps/details?id=com.call.customer';

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_the_homepage_and_footer_invite_to_the_app_with_play_badge_and_qr(): void
    {
        $html = $this->get('/')->assertOk()
            ->assertSee('data-testid="get-app"', false)
            ->assertSee('Everything local, in one app')
            ->assertSee('images/app-qr.svg', false)
            ->assertSee('Add us to your Home Screen')
            ->assertSee('data-testid="footer-app"', false)
            ->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-testid="play-badge"'));
        $this->assertStringContainsString('href="'.self::PLAY.'"', $html);
    }

    public function test_the_super_admin_can_hide_the_homepage_section(): void
    {
        Setting::set(HomeLayout::showKey('get_app'), '0');

        $this->get('/')->assertOk()->assertDontSee('data-testid="get-app"', false)
            ->assertSee('data-testid="footer-app"', false);
    }

    public function test_the_app_page_is_public_and_the_qr_link_sends_android_to_the_store(): void
    {
        $this->get('/app')->assertOk()
            ->assertSee('<h1 id="get-app-title"', false)
            ->assertSee('<title>Download the 1CallFix app', false)
            ->assertSee('name="robots" content="index, follow"', false);

        // Search engines and computers get the page, even with ?go=1.
        $this->get('/app?go=1')->assertOk();
        $this->assertStringContainsString('/app</loc>', $this->get('/sitemap-static.xml')->getContent());
        $this->withHeader('User-Agent', self::ANDROID)->get('/app?go=1')->assertRedirect(self::PLAY);
    }

    public function test_never_inside_our_android_app(): void
    {
        $app = self::ANDROID.' 1CallFixApp/customer';

        $this->withHeader('User-Agent', $app)->get('/')->assertOk()
            ->assertDontSee('data-testid="get-app"', false)
            ->assertDontSee('data-testid="footer-app"', false);
        $this->withHeader('User-Agent', $app)->get('/app')->assertRedirect('/');
    }
}
