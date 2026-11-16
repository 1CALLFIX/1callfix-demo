<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Orders\Index as OrdersIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-16: ONE "My Orders" list for every module, and a structured account menu in which each thing
 * appears once (orders are not split into My bookings / My parcels / Shop orders, and the My account page does
 * not repeat what the menu already has).
 */
class MyOrdersAllModulesTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_my_orders_lists_parcel_and_shop_orders_together_and_only_the_customers_own(): void
    {
        $shop = $this->makeMarketplaceOrderScenario('pending');
        $parcel = $this->makeParcelOrderScenario('pending');
        $parcel['order']->update(['customer_id' => $shop['customer']->id]);
        $stranger = $this->makeMarketplaceOrderScenario('pending');

        Livewire::actingAs($shop['customer'])->test(OrdersIndex::class)
            ->assertSee($shop['order']->code)
            ->assertSee($parcel['order']->code)
            ->assertSee('Parcels')
            ->assertDontSee($stranger['order']->code)
            ->set('kind', 'parcel')
            ->assertSee($parcel['order']->code)
            ->assertDontSee($shop['order']->code);
    }

    public function test_an_online_order_whose_payment_never_arrived_is_not_listed(): void
    {
        $shop = $this->makeMarketplaceOrderScenario('pending', ['payment_status' => 'pending', 'payment_method' => 'online']);

        Livewire::actingAs($shop['customer'])->test(OrdersIndex::class)->assertDontSee($shop['order']->code);
    }

    public function test_the_menu_has_one_orders_entry_and_no_per_module_order_links_and_earnings_is_not_repeated_on_my_account(): void
    {
        \App\Models\Setting::set('earnings.enabled', '1');
        \App\Models\Setting::set('earnings.wallet_tab', '1');
        $me = $this->makeCustomer();
        $this->actingAs($me);

        $menu = Blade::render('<x-customer.header />');
        $this->assertStringContainsString('My Orders', $menu);
        $this->assertStringContainsString(route('customer.orders.index'), $menu);
        $this->assertStringContainsString(route('customer.earnings.wallet'), $menu);
        foreach (['My bookings', 'My parcels', 'Shop orders', 'My tickets', 'My hall bookings'] as $gone) {
            $this->assertStringNotContainsString($gone, $menu);
        }

        $account = $this->get(route('customer.account'))->assertOk()->getContent();
        // The page itself carries the header, so Earnings is there once (the menu) and not a second time as a row.
        $this->assertSame(1, substr_count($account, route('customer.earnings.wallet')));
        $this->assertStringContainsString(route('customer.addresses'), $account);
    }
}
