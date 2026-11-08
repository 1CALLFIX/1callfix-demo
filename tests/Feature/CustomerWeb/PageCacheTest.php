<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\Setting;
use App\Support\PageCache;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner 2026-11-08: ready-made public pages for visitors who have not signed in — never personal, never stale after a save. */
class PageCacheTest extends TestCase
{
    use LiveCity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::$enabledInTests = true;
        $this->liveCity();
    }

    protected function tearDown(): void
    {
        PageCache::$enabledInTests = false;
        parent::tearDown();
    }

    /** A brand-new visitor: no session, no cookies. */
    private function newVisitor(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function csrfIn(string $html): string
    {
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    public function test_the_second_visitor_gets_the_saved_page_with_their_own_security_token(): void
    {
        $first = $this->newVisitor()->get('/app')->assertOk()->assertHeader('X-Page-Cache', 'MISS');
        $firstToken = session()->token();

        $second = $this->newVisitor()->get('/app')->assertOk()->assertHeader('X-Page-Cache', 'HIT');
        $secondToken = session()->token();

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertSame($secondToken, $this->csrfIn($second->getContent()));
        $this->assertStringNotContainsString($firstToken, $second->getContent());
        $this->assertStringNotContainsString(PageCache::TOKEN_MARK, $second->getContent());
        $this->assertStringContainsString('Everything local, in one app', $second->getContent());
    }

    public function test_signed_in_personal_or_query_visits_are_always_built_fresh(): void
    {
        $this->newVisitor()->get('/app')->assertHeader('X-Page-Cache', 'MISS');

        // Signed in.
        $user = \App\Models\User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'C', 'phone' => '9876500011', 'role' => 'customer', 'status' => 'active']);
        $this->actingAs($user)->get('/app')->assertOk()->assertHeaderMissing('X-Page-Cache');

        // Something personal in the session (a chosen location, a guest cart…).
        $this->newVisitor()->withSession(['guest_shop_cart' => [['product_id' => 1, 'qty' => 1]]])
            ->get('/app')->assertOk()->assertHeaderMissing('X-Page-Cache');

        // A flash message.
        $this->newVisitor()->withSession(['_flash' => ['new' => ['status'], 'old' => []], 'status' => 'Saved'])
            ->get('/app')->assertOk()->assertHeaderMissing('X-Page-Cache');

        // Any query string (search, page 2, ad links).
        $this->newVisitor()->get('/app?utm_source=insta')->assertOk()->assertHeaderMissing('X-Page-Cache');
    }

    public function test_an_admin_save_clears_saved_pages_at_once(): void
    {
        $this->newVisitor()->get('/app')->assertHeader('X-Page-Cache', 'MISS');
        $this->newVisitor()->get('/app')->assertHeader('X-Page-Cache', 'HIT');

        Setting::set('text.get_app.title.en', 'Nellore in your pocket');

        $this->newVisitor()->get('/app')->assertHeader('X-Page-Cache', 'MISS')->assertSee('Nellore in your pocket');
    }

    public function test_each_language_and_the_app_have_their_own_copy(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        $this->newVisitor()->get('/app')->assertHeader('X-Page-Cache', 'MISS');

        $this->newVisitor()->withCookie(SiteText::COOKIE, 'te')->get('/app')->assertHeader('X-Page-Cache', 'MISS')
            ->assertSee('స్థానిక సేవలన్నీ, ఒకే యాప్‌లో');

        // Inside the customer app the homepage hides the website-only parts, so it is its own copy.
        $this->newVisitor()->withCookie(SiteText::COOKIE, 'en')->get('/')->assertHeader('X-Page-Cache', 'MISS');
        $this->newVisitor()->withCookie(SiteText::COOKIE, 'en')
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) 1CallFixApp/customer')
            ->get('/')->assertHeader('X-Page-Cache', 'MISS')->assertDontSee('data-testid="get-app"', false);
    }

    public function test_a_private_page_is_never_saved(): void
    {
        $this->newVisitor()->get('/cart');
        $this->newVisitor()->get('/cart')->assertHeaderMissing('X-Page-Cache');
    }
}
