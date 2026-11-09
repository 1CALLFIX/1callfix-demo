<?php

namespace Tests\Feature\Parcel;

use App\Livewire\Rider\Dashboard;
use App\Livewire\Rider\Earnings;
use App\Livewire\Rider\History;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Rider app 2026-11-04 (owner: "the rider is the same for parcel, shop and food, so he should manage all deliveries"):
 * Home lists every offer together with a coloured label, runs a shop / food delivery right there, shows ONE cash-dues
 * figure (the same as Earnings), History lists every kind of finished job, and the app has a bottom bar with no
 * separate Shop tab.
 */
class RiderAllJobsTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    /** A ready delivery order of this module (store at 1,1), offered to the given rider (or a new parcel rider). */
    private function offeredOrder(string $module, string $payment = 'online', ?FieldWorker $rider = null): array
    {
        $s = $this->makeMarketplaceOrderScenario('ready', [
            'module' => $module, 'order_type' => 'delivery', 'subtotal' => 200, 'delivery_fee' => 40, 'total_amount' => 240,
            'payment_method' => $payment, 'payment_status' => $payment === 'cash' ? 'pending' : 'paid', 'delivery_address_id' => null,
        ]);
        $s['store']->update(['module' => $module, 'lat' => 1.0, 'lng' => 1.0]);
        $s['order']->update(['delivery_address_id' => $this->makeAddress($s['customer'], $s['franchise'], $s['zone'])->id, 'delivery_otp' => '4321']);
        $s['rider'] = $rider ?? $this->makeParcelRiderIn($s['franchise'], $s['zone']);
        $s['rider']->update(['is_online' => true, 'location_updated_at' => now(), 'current_lat' => 1.0, 'current_lng' => 1.0]);
        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $s['order']->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $s['rider']->id,
            'status' => 'notified', 'notified_at' => now(), 'distance_km' => 1.2,
        ]);

        return $s;
    }

    public function test_home_lists_food_and_shop_offers_with_labels_and_runs_the_delivery_there(): void
    {
        $food = $this->offeredOrder(Modules::FOOD, 'cash');
        $shop = $this->offeredOrder(Modules::COMMERCE, 'online', $food['rider']);

        $home = Livewire::actingAs($food['rider']->user)->test(Dashboard::class)
            ->assertSeeHtml('data-kind="food"')->assertSeeHtml('data-kind="shop"')
            ->assertSee($food['order']->code)->assertSee($shop['order']->code)
            ->assertDontSee('Waiting for delivery offers');

        $home->call('acceptShopOffer', $food['order']->id)
            ->assertSet('notice', 'Accepted. Head to the restaurant; take the food once they mark it ready.')
            ->assertSeeHtml('data-testid="current-job"')->assertSee('Go to the restaurant')
            ->assertDontSee('Collect ₹240.00 in cash') // owner 2026-11-09: details only once at the restaurant
            ->call('reachedStore')->assertSee('Collect from the restaurant')
            ->assertSee('Collect ₹240.00 in cash')
            ->assertDontSee($shop['order']->code); // one job at a time: no other offers while carrying one

        $home->set('shopOtp', '0000')->call('deliverShop')->assertSet('error', 'That code is not right. Ask the customer to read it from their order page.');
        $code = $food['order']->fresh()->delivery_otp; // accepting issues the customer's code
        $home->set('shopOtp', $code)->call('deliverShop')->assertSet('notice', 'Delivered. Thank you!');
        $this->assertSame('completed', $food['order']->fresh()->status);
    }

    public function test_home_and_earnings_show_the_same_cash_dues_and_history_lists_every_kind(): void
    {
        $food = $this->offeredOrder(Modules::FOOD, 'cash');
        $home = Livewire::actingAs($food['rider']->user)->test(Dashboard::class)->call('acceptShopOffer', $food['order']->id)->call('reachedStore');
        $home->set('shopOtp', $food['order']->fresh()->delivery_otp)->call('deliverShop')->assertSet('notice', 'Delivered. Thank you!');

        $owed = Livewire::actingAs($food['rider']->user)->test(Earnings::class)->viewData('owed');
        $this->assertGreaterThan(0, $owed, 'the rider holds the food order cash');
        Livewire::actingAs($food['rider']->user)->test(Dashboard::class)->assertViewHas('duesOwed', $owed);

        $parcel = $this->makeParcelOrderScenario('delivered')['order'];
        $parcel->update(['assigned_worker_id' => $food['rider']->id, 'delivered_at' => now()->subHour()]);

        Livewire::actingAs($food['rider']->user)->test(History::class)
            ->assertSeeHtml('data-kind="food"')->assertSeeHtml('data-kind="parcel"')
            ->assertSee($food['order']->code)->assertSee($parcel->code)->assertSee('Delivered');
    }

    public function test_the_rider_app_has_a_bottom_bar_without_a_separate_shop_tab(): void
    {
        $rider = $this->offeredOrder(Modules::FOOD)['rider'];

        $this->actingAs($rider->user)->get(route('rider.dashboard'))->assertOk()
            ->assertSee('aria-label="Rider sections"', false)
            ->assertSee('Jobs')->assertSee('History')->assertSee('Earnings')
            ->assertDontSee('href="'.route('rider.shop').'"', false);
    }
}
