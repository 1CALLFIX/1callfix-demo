<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Models\Commission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Partner apps 2026-11-04 (owner: "faster, easy to use, like the delivery apps"): the rider, seller/restaurant and
 * service-partner apps share one bottom bar with a More tab, and the rider's Home shows today's numbers.
 */
class PartnerAppShellTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    public function test_the_rider_app_has_the_bottom_bar_and_a_more_tab_with_payouts_help_and_sign_out(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        $this->actingAs($rider->user)->get(route('rider.dashboard'))->assertOk()
            ->assertSee('data-testid="partner-bottom-nav"', false)->assertSee(route('rider.more'), false);

        $this->actingAs($rider->user)->get(route('rider.more'))->assertOk()
            ->assertSee('Rider ID '.$rider->id)
            ->assertSee(route('rider.payment-accounts'), false)
            ->assertSee(route('rider.request-payout'), false)
            ->assertSee(route('rider.logout'), false);
    }

    public function test_the_rider_home_counts_todays_finished_jobs_and_earnings(): void
    {
        $s = $this->makeAssignedParcelOrderScenario();
        $s['order']->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
        Commission::create(['parcel_order_id' => $s['order']->id, 'provider_commission' => 62.5, 'franchise_commission' => 0, 'platform_commission' => 10]);

        // A job finished yesterday does not count.
        $old = $this->makeAssignedParcelOrderScenario();
        $old['order']->forceFill(['assigned_worker_id' => $s['rider']->id, 'status' => 'delivered', 'delivered_at' => now()->subDays(2)])->save();

        Livewire::actingAs($s['rider']->user)->test(RiderDashboard::class)
            ->assertViewHas('today', ['jobs' => 1, 'earned' => 62.5])
            ->assertSee('Earned today')->assertSee('62.50')
            ->assertSee('data-testid="online-switch"', false);
    }

    public function test_the_seller_app_has_four_daily_tabs_and_shop_details_on_more(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $owner = $this->makeStoreOwner($franchise, $zone);
        $store = $this->makeStore($franchise, $zone, $owner);

        $this->actingAs($owner->user)->get(route('seller.dashboard'))->assertOk()
            ->assertSee('data-testid="partner-bottom-nav"', false)
            ->assertSee(route('seller.orders'), false)->assertSee(route('seller.products'), false)
            ->assertSee(route('seller.earnings'), false)->assertSee(route('seller.more'), false);

        $this->actingAs($owner->user)->get(route('seller.more'))->assertOk()
            ->assertSee($store->name)->assertSee('Shop details')
            ->assertSee(route('seller.shop'), false)->assertSee(route('seller.payment-accounts'), false)
            ->assertSee(route('seller.logout'), false)
            ->assertDontSee(route('seller.add-ons'), false); // add-ons are for restaurants only
    }

    public function test_the_more_tabs_need_the_right_partner_role(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer)->get(route('rider.more'))->assertStatus(403);
        $this->actingAs($customer)->get(route('seller.more'))->assertStatus(403);
    }
}
