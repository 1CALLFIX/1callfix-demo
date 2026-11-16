<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Orders\Index as OrdersIndex;
use App\Models\ParcelPack;
use App\Models\ParcelPackPurchase;
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

    public function test_a_paid_parcel_pack_is_listed_with_its_credits_even_when_used_up_and_an_unpaid_one_is_not(): void
    {
        $me = $this->makeCustomer();
        $pack = ParcelPack::create(['name' => 'Pack', 'parcel_count' => 10, 'max_km' => 5, 'price' => 499, 'validity_days' => 30]);
        ParcelPackPurchase::create(['parcel_pack_id' => $pack->id, 'user_id' => $me->id, 'name' => 'Weekly 10 pack', 'parcels_total' => 10, 'parcels_used' => 10, 'max_km' => 5, 'validity_days' => 30,
            'price_paid' => 499, 'status' => 'expired', 'payment_method' => 'online', 'paid_at' => now()->subDays(40)]);
        ParcelPackPurchase::create(['parcel_pack_id' => $pack->id, 'user_id' => $me->id, 'name' => 'Never paid pack', 'parcels_total' => 5, 'parcels_used' => 0, 'max_km' => 5, 'validity_days' => 30,
            'price_paid' => 299, 'status' => 'pending_payment', 'payment_method' => 'online']);

        Livewire::actingAs($me)->test(OrdersIndex::class)
            ->assertSee('Weekly 10 pack')->assertSee('0 of 10 parcels left')->assertSee('Parcel packs')
            ->assertDontSee('Never paid pack');
    }

    public function test_the_menu_offers_wallet_refer_coupons_contact_and_a_call_whatsapp_strip_and_rate_review_only_in_a_browser(): void
    {
        \App\Models\Setting::set('earnings.enabled', '1');
        \App\Models\Setting::set('earnings.wallet_tab', '1');
        \App\Models\Setting::set('earnings.referral_tab', '1');
        \App\Models\Setting::set('referral.enabled', '1');
        \App\Models\Setting::set(\App\Support\InfoPages\ContactDetails::key('phone'), '+91 98765 43210');
        \App\Models\Setting::set(\App\Support\InfoPages\ContactDetails::key('whatsapp'), '919876543210');
        \App\Models\Setting::set(\App\Services\Reviews\GoogleReviews::REVIEW_URL, 'https://g.page/r/abc123/review');
        $this->actingAs($this->makeCustomer());

        $menu = Blade::render('<x-customer.header />');
        $this->assertStringContainsString(route('customer.earnings.wallet'), $menu);
        $this->assertStringContainsString(route('customer.earnings.referrals'), $menu);
        $this->assertStringContainsString(route('customer.offers'), $menu);
        $this->assertStringContainsString(route('customer.contact'), $menu);
        $this->assertStringContainsString('Refer a friend', $menu);
        $this->assertStringContainsString('Coupons', $menu);
        $this->assertStringContainsString('tel:+919876543210', $menu);
        $this->assertStringContainsString('https://wa.me/919876543210', $menu);
        $this->assertStringContainsString('Rate &amp; Review', $menu);
        $this->assertStringContainsString('https://g.page/r/abc123/review', $menu);

        // Inside the Android app the Google link is not shown (the Play Store rating is).
        $this->withHeader('User-Agent', 'Mozilla/5.0 1CallFixApp/customer');
        request()->headers->set('User-Agent', 'Mozilla/5.0 1CallFixApp/customer');
        $inApp = Blade::render('<x-customer.header />');
        $this->assertStringNotContainsString('https://g.page/r/abc123/review', $inApp);
    }

    public function test_the_call_strip_and_rate_review_are_hidden_while_nothing_is_set(): void
    {
        $this->actingAs($this->makeCustomer());

        $menu = Blade::render('<x-customer.header />');
        $this->assertStringNotContainsString('Need help?', $menu);
        $this->assertStringNotContainsString('Rate &amp; Review', $menu);
        $this->assertStringContainsString(route('customer.contact'), $menu);
    }
}
