<?php

namespace Tests\Feature\Partners;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-09: "a customer downloaded the rider app, logged in / created the account, and the rider app showed
 * the customer app (same in the partner app); I had to open the menu → Rider dashboard". Inside the Rider or Partner
 * app the customer homepage is never shown: every sign-in / sign-up path that ends there lands on that app's own home.
 * Signed out, the rider area asks for the rider sign-in, not the customer one. Browsers and the Customer app unchanged.
 */
class AppOpensOwnHomeTest extends TestCase
{
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    private const RIDER_UA = 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile 1CallFixApp/rider';
    private const PARTNER_UA = 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile 1CallFixApp/partner';
    private const CUSTOMER_UA = 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile 1CallFixApp/customer';

    private function customer(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active',
        ]);
    }

    public function test_signed_out_the_rider_area_asks_for_the_rider_sign_in_everywhere(): void
    {
        $this->get('/rider')->assertRedirect(route('rider.login'));
        $this->withHeader('User-Agent', self::RIDER_UA)->get('/rider')->assertRedirect(route('rider.login'));
    }

    public function test_in_the_rider_app_the_homepage_goes_to_the_riders_own_home(): void
    {
        // Signed out: rider sign-in.
        $this->withHeader('User-Agent', self::RIDER_UA)->get('/')->assertRedirect(route('rider.login'));

        // A rider (signed in through the customer sign-in or a sign-up that ends on the homepage): his dashboard.
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $rider = $this->makeParcelRiderIn($franchise, $zone);
        $this->actingAs($rider->user)->withHeader('User-Agent', self::RIDER_UA)->get('/')->assertRedirect(route('rider.dashboard'));
    }

    public function test_in_the_rider_app_a_customer_who_is_not_a_rider_yet_goes_to_the_join_page(): void
    {
        $this->actingAs($this->customer())->withHeader('User-Agent', self::RIDER_UA)->get('/')
            ->assertRedirect(route('customer.partners'));
    }

    public function test_in_the_partner_app_the_homepage_goes_to_the_partner_start_page(): void
    {
        $this->withHeader('User-Agent', self::PARTNER_UA)->get('/')->assertRedirect(route('partner.app.start'));
        $this->actingAs($this->customer())->withHeader('User-Agent', self::PARTNER_UA)->get('/')->assertRedirect(route('partner.app.start'));
    }

    public function test_browsers_and_the_customer_app_still_get_the_homepage(): void
    {
        $this->get('/')->assertOk();
        $this->withHeader('User-Agent', self::CUSTOMER_UA)->get('/')->assertOk();
        $this->actingAs($this->customer())->get('/')->assertOk();
    }
}
