<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\Setting;
use App\Services\Customer\CustomerLocationContext;
use App\Support\LiteSpeed;
use App\Support\PageCache;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner 2026-11-08: the web server's own page cache — only ever a public, cookie-free, token-free copy. */
class LiteSpeedCacheTest extends TestCase
{
    use LiveCity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::$enabledInTests = true;
        config(['app.lscache' => true]);
        $this->liveCity();
        LiteSpeed::$purge = false; // creating the test city above is itself a save
    }

    protected function tearDown(): void
    {
        PageCache::$enabledInTests = false;
        LiteSpeed::$purge = false;
        parent::tearDown();
    }

    private function newVisitor(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }

    private function csrfIn(string $html): ?string
    {
        return preg_match('/<meta name="csrf-token" content="([^"]*)"/', $html, $m) ? $m[1] : null;
    }

    public function test_a_new_website_visitor_gets_a_public_copy_with_no_cookies_and_no_token(): void
    {
        foreach (['MISS', 'HIT'] as $round) {
            $r = $this->newVisitor()->get('/app')->assertOk()
                ->assertHeader('X-LiteSpeed-Cache-Control', 'public, max-age=60')
                ->assertHeaderMissing(LiteSpeed::MARK);
            $this->assertSame([], $r->headers->getCookies(), $round.': a kept copy never carries anyone\'s cookie');
            $this->assertSame('', $this->csrfIn($r->getContent()), $round);
            $this->assertStringNotContainsString(PageCache::TOKEN_MARK, $r->getContent());
            $this->assertStringNotContainsString((string) session()->token(), $r->getContent());
        }
    }

    public function test_off_by_default(): void
    {
        config(['app.lscache' => false]);

        $r = $this->newVisitor()->get('/app')->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control')->assertHeaderMissing(LiteSpeed::MARK);
        $this->assertSame((string) session()->token(), $this->csrfIn($r->getContent()));
    }

    public function test_signed_in_personal_app_and_other_language_visitors_are_never_kept_and_get_marked(): void
    {
        $user = \App\Models\User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'C', 'phone' => '9876500012', 'role' => 'customer', 'status' => 'active']);
        $r = $this->actingAs($user)->get('/app')->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control');
        $this->assertSame('1', $this->cookieValue($r, LiteSpeed::COOKIE_PERSONAL));

        $r = $this->newVisitor()->withSession(['guest_shop_cart' => [['product_id' => 1, 'qty' => 1]]])->get('/app')
            ->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control');
        $this->assertSame('1', $this->cookieValue($r, LiteSpeed::COOKIE_PERSONAL));
        $this->assertSame((string) session()->token(), $this->cookieValue($r, LiteSpeed::COOKIE_TOKEN));

        $this->newVisitor()->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) 1CallFixApp/customer')
            ->get('/')->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control');

        $this->newVisitor()->withUnencryptedCookie(SiteText::COOKIE, 'en')->withHeader('User-Agent', 'Mozilla/5.0')
            ->get('/app')->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control');
    }

    public function test_a_city_page_copy_asks_hello_to_set_the_city_and_hello_gives_the_token_and_city(): void
    {
        $r = $this->newVisitor()->get('/nellore/services')->assertOk()
            ->assertHeader('X-LiteSpeed-Cache-Control', 'public, max-age=60');
        $this->assertStringContainsString('<meta name="cf-hello" content="1">', $r->getContent());

        $hello = $this->newVisitor()->getJson('/_hello?path='.urlencode('/nellore/services'))->assertOk();
        $this->assertSame(session()->token(), $hello->json('token'));
        $this->assertNotNull(session(CustomerLocationContext::SESSION_KEY), 'the city is set as building the page would');
        $this->assertSame((string) session(CustomerLocationContext::SESSION_KEY), $this->cookieValue($hello, LiteSpeed::COOKIE_ZONE));
        $this->assertSame(session()->token(), $this->cookieValue($hello, LiteSpeed::COOKIE_TOKEN));
        $this->assertStringContainsString('no-store', (string) $hello->headers->get('Cache-Control'));

        // With a zone the visitor goes to PHP (one copy per zone there), never the web server's copy.
        $this->get('/nellore/services')->assertOk()->assertHeaderMissing('X-LiteSpeed-Cache-Control');
    }

    public function test_an_admin_save_tells_the_web_server_to_drop_its_copies(): void
    {
        $this->newVisitor()->get('/app')->assertHeaderMissing('X-LiteSpeed-Purge');

        Setting::set('text.get_app.title.en', 'Nellore in your pocket');

        $this->newVisitor()->get('/app')->assertHeader('X-LiteSpeed-Purge', '*')->assertSee('Nellore in your pocket');
        $this->newVisitor()->get('/app')->assertHeaderMissing('X-LiteSpeed-Purge');
    }

    private function cookieValue(\Illuminate\Testing\TestResponse $r, string $name): ?string
    {
        foreach ($r->headers->getCookies() as $c) {
            if ($c->getName() === $name) {
                return $c->getValue();
            }
        }

        return null;
    }
}
