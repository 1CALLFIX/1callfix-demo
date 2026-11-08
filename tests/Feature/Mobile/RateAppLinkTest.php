<?php

namespace Tests\Feature\Mobile;

use App\Models\Setting;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/** Owner 2026-11-05: "Rate & review" inside each Android app opens that app's Play Store page; never on the website. */
class RateAppLinkTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36';

    private function app(string $flavor): string
    {
        return self::BROWSER.' 1CallFixApp/'.$flavor;
    }

    private function play(string $package): string
    {
        return 'https://play.google.com/store/apps/details?id='.$package;
    }

    public function test_the_customer_app_menu_has_rate_and_review_and_chrome_does_not(): void
    {
        $this->liveCity();
        $this->makeCategoryAndService();

        $this->withHeader('User-Agent', $this->app('customer'))->get('/')->assertOk()
            ->assertSee('Rate &amp; review', false)->assertSee($this->play('com.call.customer'), false);

        $this->withHeader('User-Agent', self::BROWSER)->get('/')->assertOk()
            ->assertDontSee('Rate &amp; review', false);
        // The website's Play links are the "Get the app" strip, section and footer badge (owner 2026-11-07/08), never the rate link.
        $this->assertGreaterThanOrEqual(1, substr_count($this->withHeader('User-Agent', self::BROWSER)->get('/')->getContent(), 'play.google.com/store/apps'));
    }

    public function test_the_rider_seller_and_partner_more_tabs_open_their_own_play_page(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $rider = $this->makeParcelRiderIn($franchise, $zone);
        $owner = $this->makeStoreOwner($franchise, $zone);
        $this->makeStore($franchise, $zone, $owner);
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->actingAs($rider->user)->withHeader('User-Agent', $this->app('rider'))->get(route('rider.more'))->assertOk()
            ->assertSee('data-testid="rate-app"', false)->assertSee($this->play('com.onecall.deliveryboy'), false);
        $this->actingAs($owner->user)->withHeader('User-Agent', $this->app('partner'))->get(route('seller.more'))->assertOk()
            ->assertSee($this->play('com.onecall.vendor'), false);
        $this->actingAs($provider->user)->withHeader('User-Agent', $this->app('partner'))->get(route('provider.more'))->assertOk()
            ->assertSee($this->play('com.onecall.vendor'), false);

        // An app build without the flavor tag: the screen's own app.
        $this->actingAs($rider->user)->withHeader('User-Agent', self::BROWSER.' 1CallFixApp')->get(route('rider.more'))->assertOk()
            ->assertSee($this->play('com.onecall.deliveryboy'), false);

        // Chrome: no row.
        foreach ([[$rider->user, 'rider.more'], [$owner->user, 'seller.more'], [$provider->user, 'provider.more']] as [$user, $route]) {
            $this->actingAs($user)->withHeader('User-Agent', self::BROWSER)->get(route($route))->assertOk()
                ->assertDontSee('data-testid="rate-app"', false);
        }
    }

    public function test_the_label_is_site_text_with_telugu(): void
    {
        $this->assertSame('Rate & review', SiteText::get('app.rate'));
        $this->liveCity();
        $this->makeCategoryAndService();
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        $this->withHeader('User-Agent', $this->app('customer'))->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()
            ->assertSee('రేటింగ్ &amp; రివ్యూ ఇవ్వండి', false);
    }
}
