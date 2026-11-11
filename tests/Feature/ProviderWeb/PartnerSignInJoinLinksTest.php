<?php

namespace Tests\Feature\ProviderWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-11-11: every partner sign-in has the same short "New to 1CallFix? Become a …" line, and it opens /partners
 * with that person's role already picked (rider → Delivery rider, shop → Seller or shop, restaurant → Restaurant
 * partner, service → Service professional). Inside the Rider / Partner apps the "Customer sign in" line is hidden
 * (the app sends the customer home back to its own sign-in, a dead end); on the website it stays.
 */
class PartnerSignInJoinLinksTest extends TestCase
{
    use RefreshDatabase;

    private const APP_UA = 'Mozilla/5.0 (Linux; Android 10; K) Chrome/156.0 Mobile Safari/537.36 1CallFixApp/rider';

    public function test_rider_sign_in_says_become_a_rider_and_opens_the_delivery_rider_role(): void
    {
        $this->get(route('rider.login'))->assertOk()
            ->assertSee('Become a rider')
            ->assertDontSee('Apply on the partner page')
            ->assertSee(route('customer.partners', ['role' => 'parcel']).'#formCard', false);
    }

    public function test_seller_and_partner_sign_ins_open_their_own_roles(): void
    {
        $this->get(route('seller.login'))->assertOk()
            ->assertSee(route('customer.partners', ['role' => 'commerce']).'#formCard', false)
            ->assertSee(route('customer.partners', ['role' => 'food']).'#formCard', false)
            ->assertDontSee('Apply on the partner page');

        $this->get(route('provider.login'))->assertOk()
            ->assertSee('Become a partner')
            ->assertSee(route('customer.partners', ['role' => 'service']).'#formCard', false);
    }

    public function test_customer_sign_in_line_shows_on_the_website_only(): void
    {
        foreach (['rider.login' => 'Not a rider?', 'provider.login' => 'Not a partner?', 'seller.login' => 'Not a seller?'] as $route => $line) {
            $this->get(route($route))->assertOk()->assertSee($line);
            $this->withHeader('User-Agent', self::APP_UA)->get(route($route))->assertOk()->assertDontSee($line);
            $this->withHeader('User-Agent', 'Mozilla/5.0');
        }
    }
}
