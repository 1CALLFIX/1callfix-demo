<?php

namespace Tests\Feature\Partners;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-26: a partner who switched from the Partner app to the Rider app saw the same screen and could not
 * tell which app he was in. Every partner app shows its own colour and icon (x-partner.app-badge) in the header
 * (strip + badge) and its theme colour; the Partner and Rider online cards also name the app, because online is
 * separate in each.
 */
class PartnerAppIdentityTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** test login => [dashboard route, app key, theme colour] */
    private const APPS = [
        'test.provider' => ['provider.dashboard', 'partner', '#2563eb'],
        'test.rider' => ['rider.dashboard', 'rider', '#f97316'],
        'test.seller' => ['seller.dashboard', 'seller', '#059669'],
        'test.restaurant' => ['seller.dashboard', 'restaurant', '#e11d48'],
        'test.hotel' => ['hotel.dashboard', 'hotel', '#7c3aed'],
        'test.hall' => ['venue.dashboard', 'venue', '#0d9488'],
        'test.organiser' => ['organiser.dashboard', 'organiser', '#db2777'],
    ];

    public function test_every_partner_app_shows_its_own_colour_and_name(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();

        foreach (self::APPS as $login => [$route, $app, $theme]) {
            $user = User::where('email', $login.'@1callfix.test')->firstOrFail();
            $response = $this->actingAs($user)->get(route($route));

            $response->assertOk();
            $response->assertSee('data-partner-app="'.$app.'"', false);
            $response->assertSee('<meta name="theme-color" content="'.$theme.'">', false);
            foreach (array_diff(array_column(self::APPS, 1), [$app]) as $other) {
                $response->assertDontSee('data-partner-app="'.$other.'"', false);
            }
        }
    }

    public function test_the_partner_and_rider_online_cards_name_their_app(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();

        $this->actingAs(User::where('email', 'test.provider@1callfix.test')->firstOrFail())
            ->get(route('provider.dashboard'))->assertOk()->assertSee('Service Partner app');
        $this->actingAs(User::where('email', 'test.rider@1callfix.test')->firstOrFail())
            ->get(route('rider.dashboard'))->assertOk()->assertSee('Delivery Rider app');
    }
}
