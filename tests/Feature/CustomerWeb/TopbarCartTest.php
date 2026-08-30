<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\CartCount;
use App\Services\Customer\ServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The topbar: the services-cart icon + live count, and the mobile search
 * drawer toggle.
 */
class TopbarCartTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_a_signed_in_customer_sees_the_cart_link_with_its_count(): void
    {
        $customer = $this->makeCustomer();
        $cart = app(ServiceCartService::class);
        $cart->add($customer, $this->makeService($this->makeCategory(), ['name' => 'A']), quantity: 2);
        $cart->add($customer, $this->makeService($this->makeCategory(), ['name' => 'B']), quantity: 1);

        Livewire::actingAs($customer)->test(CartCount::class)
            ->assertSet('count', 3)
            ->assertSee(route('customer.cart'))
            ->assertSee('3');
    }

    public function test_the_badge_is_hidden_when_the_cart_is_empty(): void
    {
        Livewire::actingAs($this->makeCustomer())->test(CartCount::class)
            ->assertSet('count', 0)
            ->assertDontSee('<span aria-hidden="true"', escape: false);
    }

    public function test_the_count_refreshes_on_the_cart_updated_event(): void
    {
        $customer = $this->makeCustomer();
        $component = Livewire::actingAs($customer)->test(CartCount::class)->assertSet('count', 0);

        app(ServiceCartService::class)->add($customer, $this->makeService($this->makeCategory()));

        $component->dispatch('cart-updated')->assertSet('count', 1);
    }

    public function test_the_guest_header_has_no_cart_link(): void
    {
        $this->get(route('customer.home'))
            ->assertOk()
            ->assertDontSee(route('customer.cart'));
    }

    public function test_the_header_carries_the_mobile_search_drawer_toggle(): void
    {
        $this->get(route('customer.home'))
            ->assertOk()
            ->assertSee('data-search-toggle', escape: false)
            ->assertSee('data-search-drawer', escape: false);
    }

    public function test_the_homepage_location_pill_opens_the_picker(): void
    {
        $this->get(route('customer.home'))
            ->assertOk()
            ->assertSee("\$dispatch('open-location-picker')", escape: false);
    }
}
