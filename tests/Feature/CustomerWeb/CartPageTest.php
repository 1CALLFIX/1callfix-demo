<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Cart\Index as CartIndex;
use App\Services\Customer\ServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The cart page (App\Livewire\Customer\Cart\Index): visit grouping, per-line
 * edits, IDOR, and the route out to checkout.
 */
class CartPageTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function cart(): ServiceCartService
    {
        return app(ServiceCartService::class);
    }

    public function test_the_page_requires_a_login(): void
    {
        $this->get(route('customer.cart'))->assertRedirect(route('customer.login'));
    }

    public function test_empty_cart_shows_the_empty_state(): void
    {
        Livewire::actingAs($this->makeCustomer())->test(CartIndex::class)
            ->assertSee('Your cart is empty');
    }

    public function test_lines_render_grouped_into_visits_by_subcategory(): void
    {
        $customer = $this->makeCustomer();
        $appliance = $this->makeCategory(['name' => 'AC & Appliance Repair']);
        $acSub = $this->makeSubcategory($appliance, ['name' => 'AC Repair']);

        $this->cart()->add($customer, $this->makeService($appliance, ['subcategory_id' => $acSub->id, 'name' => 'Window AC Service']));
        $this->cart()->add($customer, $this->makeService($appliance, ['subcategory_id' => $acSub->id, 'name' => 'Fridge Repair']));
        $this->cart()->add($customer, $this->makeService($this->makeCategory(['name' => 'Plumbing']), ['name' => 'Leaky Tap']));

        Livewire::actingAs($customer)->test(CartIndex::class)
            ->assertSee('AC Repair')
            ->assertSee('Plumbing')
            ->assertSee('Window AC Service')
            ->assertSee('Fridge Repair')
            ->assertSee('Leaky Tap');
    }

    public function test_quantity_and_remove_mutate_only_the_targeted_line(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->cart()->add($customer, $this->makeService($this->makeCategory(), ['name' => 'A']));
        $b = $this->cart()->add($customer, $this->makeService($this->makeCategory(), ['name' => 'B']));

        Livewire::actingAs($customer)->test(CartIndex::class)
            ->call('changeQty', $a->id, 2)
            ->call('removeItem', $b->id);

        $this->assertSame(3, $a->fresh()->quantity);
        $this->assertModelMissing($b);
    }

    public function test_a_cart_item_id_from_another_customer_is_ignored(): void
    {
        $me = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $theirs = $this->cart()->add($stranger, $this->makeService($this->makeCategory()));

        Livewire::actingAs($me)->test(CartIndex::class)
            ->call('changeQty', $theirs->id, 5)
            ->call('removeItem', $theirs->id);

        $this->assertSame(1, $theirs->fresh()->quantity);
    }

    public function test_proceed_routes_to_checkout(): void
    {
        $customer = $this->makeCustomer();
        $this->cart()->add($customer, $this->makeService($this->makeCategory()));

        Livewire::actingAs($customer)->test(CartIndex::class)
            ->call('proceed')
            ->assertRedirect(route('customer.checkout'));
    }
}
