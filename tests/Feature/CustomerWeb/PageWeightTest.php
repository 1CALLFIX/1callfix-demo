<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Speed guard: a guest — most first visits — must not download the Firebase push bundle, which only a signed-in
 * customer can use; and the hashed build files must be cacheable for a year without ever caching the manifest.
 */
class PageWeightTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_a_guest_page_does_not_load_the_push_bundle(): void
    {
        $this->get('/')->assertOk()->assertDontSee('push-notifications', false);
    }

    public function test_a_signed_in_customer_page_still_loads_the_push_bundle(): void
    {
        $this->actingAs($this->makeCustomer())->get('/')->assertOk()->assertSee('push-notifications', false);
    }

    public function test_only_hashed_build_assets_get_the_year_long_cache_rule(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('RewriteRule ^build/assets/ - [E=ONECF_IMMUTABLE_ASSET:1]', $htaccess);
        $this->assertStringContainsString('max-age=31536000, immutable" env=ONECF_IMMUTABLE_ASSET', $htaccess);
        $this->assertSame(1, substr_count($htaccess, 'ONECF_IMMUTABLE_ASSET:1'), 'exactly one rule sets the immutable flag');
        $this->assertStringNotContainsString('RewriteRule ^build/manifest', $htaccess);
    }
}
