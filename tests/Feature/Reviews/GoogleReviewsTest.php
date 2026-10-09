<?php

namespace Tests\Feature\Reviews;

use App\Livewire\Reviews\GoogleSettings;
use App\Livewire\Customer\Home;
use App\Models\Setting;
use App\Services\Reviews\GoogleReviews as G;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Google Business reviews on the home page: hidden until real, fresh data exists; every knob is an admin setting;
 * Google-only links; escaped text; the 30-day freshness rule; the key only from the server env; Super Admin screen.
 */
class GoogleReviewsTest extends TestCase
{
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    private const PLACE = 'ChIJy4lRhCzzTDoRjje_ODxoNrw';

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        config(['services.google_places.key' => 'test-key']);
    }

    private function enable(array $extra = []): void
    {
        Setting::set(G::ENABLED, '1');
        Setting::set(G::PLACE_ID, self::PLACE);
        foreach ($extra as $k => $v) {
            Setting::set($k, (string) $v);
        }
    }

    private function fakeGoogle(array $reviews = null): void
    {
        $reviews ??= [
            ['authorAttribution' => ['displayName' => 'Asha', 'uri' => 'https://www.google.com/maps/contrib/1', 'photoUri' => 'https://lh3.googleusercontent.com/a'],
             'rating' => 5, 'text' => ['text' => 'Great AC service, on time.'], 'relativePublishTimeDescription' => 'a week ago'],
            ['authorAttribution' => ['displayName' => 'Ravi'], 'rating' => 2, 'text' => ['text' => 'Late arrival.'], 'relativePublishTimeDescription' => '2 weeks ago'],
            ['authorAttribution' => ['displayName' => 'Noor'], 'rating' => 5, 'text' => ['text' => ''], 'relativePublishTimeDescription' => 'a month ago'],
        ];

        Http::fake(['places.googleapis.com/*' => Http::response([
            'displayName' => ['text' => '1CallFix'], 'rating' => 4.8, 'userRatingCount' => 123,
            'googleMapsUri' => 'https://maps.google.com/?cid=1', 'reviews' => $reviews,
        ])]);
    }

    public function test_nothing_renders_until_it_is_switched_on_and_fetched(): void
    {
        $this->get('/')->assertOk()->assertDontSee('What customers say on Google');

        $this->enable();
        $this->get('/')->assertOk()->assertDontSee('What customers say on Google'); // on, but no data yet
    }

    public function test_a_refresh_shows_only_real_reviews_that_pass_the_owners_minimum(): void
    {
        $this->enable([G::REVIEW_URL => 'https://g.page/r/CY43vzg8aDa8EBM/review']);
        $this->fakeGoogle();

        $this->assertStringStartsWith('Refreshed', app(G::class)->refresh(true));

        $this->get('/')->assertOk()
            ->assertSee('What customers say on Google')
            ->assertSee('Great AC service, on time.')
            ->assertSee('4.8')
            ->assertSee('123 Google reviews')
            ->assertSee('Write a review')
            ->assertDontSee('Late arrival.')   // below the default 4-star minimum
            ->assertDontSee('Noor');           // empty text is never shown
    }

    public function test_the_minimum_and_the_count_are_admin_settings(): void
    {
        $this->enable([G::MIN_RATING => 1, G::MAX => 1]);
        $this->fakeGoogle();
        app(G::class)->refresh(true);

        $shown = app(G::class)->display();
        $this->assertCount(1, $shown['reviews']);
    }

    public function test_review_text_is_escaped_and_non_google_links_are_dropped(): void
    {
        $this->enable();
        $this->fakeGoogle([[
            'authorAttribution' => ['displayName' => 'X', 'uri' => 'https://evil.example.com/p', 'photoUri' => 'http://insecure.example/p.png'],
            'rating' => 5, 'text' => ['text' => '<script>alert(1)</script> nice'], 'relativePublishTimeDescription' => 'today',
        ]]);
        app(G::class)->refresh(true);

        $review = app(G::class)->display()['reviews'][0];
        $this->assertNull($review['author_url']);
        $this->assertNull($review['photo']);

        $this->get('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_a_copy_older_than_thirty_days_is_hidden(): void
    {
        $this->enable();
        $this->fakeGoogle();
        app(G::class)->refresh(true);
        $this->assertNotNull(app(G::class)->display());

        Setting::set(G::FETCHED_AT, (string) now()->subDays(G::MAX_AGE_DAYS + 1)->timestamp);
        $this->assertNull(app(G::class)->display());
    }

    public function test_a_failed_refresh_keeps_the_old_copy_and_records_the_reason(): void
    {
        $this->enable();
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push(['rating' => 4.8, 'userRatingCount' => 5, 'reviews' => [['authorAttribution' => ['displayName' => 'A'], 'rating' => 5, 'text' => ['text' => 'Good.']]]])
            ->push(['error' => ['message' => 'API key not valid']], 403)]);

        $this->assertStringStartsWith('Refreshed', app(G::class)->refresh(true));
        $this->assertStringStartsWith('Failed', app(G::class)->refresh(true));

        $this->assertNotNull(app(G::class)->display());
        $this->assertStringContainsString('API key not valid', (string) Setting::get(G::LAST_ERROR));
    }

    public function test_it_does_not_call_google_without_the_key_or_while_fresh_or_with_a_bad_place_id(): void
    {
        Http::fake();
        $this->enable();

        config(['services.google_places.key' => '']);
        $this->assertStringContainsString('GOOGLE_PLACES_API_KEY', app(G::class)->refresh(true));

        config(['services.google_places.key' => 'k']);
        Setting::set(G::PLACE_ID, 'bad id/../x');
        $this->assertStringContainsString('no valid Place ID', app(G::class)->refresh(true));

        Http::assertNothingSent();
    }

    public function test_the_scheduled_command_respects_the_refresh_interval(): void
    {
        $this->enable([G::REFRESH_HOURS => 24]);
        $this->fakeGoogle();

        $this->artisan('google-reviews:refresh')->assertSuccessful();
        $this->artisan('google-reviews:refresh')->assertSuccessful(); // still fresh → no second call

        Http::assertSentCount(1);
    }

    public function test_only_a_super_admin_can_use_the_screen_and_changes_are_validated_and_logged(): void
    {
        $editor = $this->makeUserWithPermission('settings.manage', 'global');
        Livewire::actingAs($editor)->test(GoogleSettings::class)->assertForbidden();

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(GoogleSettings::class)
            ->set('placeId', 'not valid!')->set('reviewUrl', 'https://evil.example.com/x')->set('max', '9')
            ->call('save')->assertHasErrors(['placeId', 'reviewUrl', 'max']);

        Livewire::actingAs($admin)->test(GoogleSettings::class)
            ->set('enabled', true)->set('placeId', self::PLACE)->set('reviewUrl', 'https://g.page/r/CY43vzg8aDa8EBM/review')
            ->set('max', '3')->set('minRating', '5')->set('refreshHours', '12')
            ->call('save')->assertHasNoErrors()->assertSet('flashMessage', 'Google reviews settings saved.');

        $this->assertTrue(G::enabled());
        $this->assertSame(3, G::max());
        $this->assertSame(5, G::minRating());
        $this->assertSame('https://g.page/r/CY43vzg8aDa8EBM/review', G::reviewUrl());
    }
}
