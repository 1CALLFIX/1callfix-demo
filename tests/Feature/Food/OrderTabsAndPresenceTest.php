<?php

namespace Tests\Feature\Food;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\CartService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-09: the restaurant gets one tab per step (New, Cooking, Ready, Handed over, Completed) and a progress
 * line on each order, and the screen follows the order after each button. The admin partner lists show what dispatch
 * uses: "Taking jobs" / "On duty · no location" / "Off duty", not just "app used in the last 5 minutes".
 */
class OrderTabsAndPresenceTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private $franchise;
    private $zone;
    private User $customer;
    private Store $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        [, , $this->franchise, $this->zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($this->franchise, Modules::FOOD);
        $this->customer = $this->makeCustomer();
        $this->restaurant = $this->makeStore($this->franchise, $this->zone, null, [
            'module' => Modules::FOOD, 'name' => 'Annapurna Mess', 'fssai_number' => '12345678901234',
            'delivery_fee_flat' => 30, 'prep_minutes' => 20,
        ]);
    }

    private function order(): MarketplaceOrder
    {
        $dish = $this->makeProduct($this->restaurant, ['name' => 'Veg Meals', 'price' => 100, 'stock' => 9999, 'food_type' => 'veg']);
        app(CartService::class)->add($this->customer, $dish, 1);
        $address = $this->makeAddress($this->customer, $this->franchise, $this->zone);

        return app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $this->restaurant->id, 'customer_id' => $this->customer->id,
            'order_type' => 'delivery', 'payment_method' => 'cash', 'delivery_address_id' => $address->id,
        ]);
    }

    public function test_the_order_walks_through_one_tab_per_step(): void
    {
        $order = $this->order();
        $page = Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)
            ->assertSee('Cooking')->assertSee('Ready')->assertSee('Handed over')
            ->assertSee($order->code);

        $page->call('advance', $order->id, 'accepted')->assertSet('tab', 'cooking')->assertSee($order->code)->assertSee('Start cooking');
        $page->call('advance', $order->id, 'preparing')->assertSet('tab', 'cooking')->assertSee('Mark ready');
        $page->call('advance', $order->id, 'ready')->assertSet('tab', 'ready')->assertSee($order->code)->assertSee('Pickup code');

        // Not in Cooking any more.
        $page->set('tab', 'cooking')->assertDontSee($order->code);

        // A rider takes it and the restaurant hands it over: it moves to Handed over.
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $order->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $rider->id, 'status' => 'notified', 'notified_at' => now(),
        ]);
        app(AcceptMarketplaceDeliveryOfferAction::class)->execute($order->id, $rider);
        $order->forceFill(['rider_at_store_at' => now()])->save(); // he tapped "Reached restaurant"

        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'ready')
            ->set("riderCodes.{$order->id}", $order->fresh()->pickup_code)->call('handToRider', $order->id)
            ->assertSet('tab', 'handed')->assertSee($order->code)->assertSee('Picked up by');

        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'ready')
            ->assertDontSee($order->code);
    }

    public function test_the_old_in_progress_link_still_opens(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => 'preparing'])->save();

        Livewire::actingAs($this->restaurant->provider->user)->withQueryParams(['tab' => 'active'])->test(SellerOrders::class)
            ->assertSee($order->code)->assertSeeHtml('data-testid="order-steps"');
    }

    public function test_the_admin_partner_column_shows_what_dispatch_uses(): void
    {
        $user = $this->makeCustomer();
        $user->forceFill(['last_seen_at' => now()])->save(); // app used a minute ago

        $html = fn (bool $onDuty, $locationAt) => Blade::render('<x-ui.presence :user="$u" :on-duty="$d" :location-at="$l" />', ['u' => $user->fresh(), 'd' => $onDuty, 'l' => $locationAt]);

        $off = $html(false, now());
        $this->assertStringContainsString('Off duty', $off);
        $this->assertStringContainsString('App open now', $off);
        $this->assertStringNotContainsString('Taking jobs', $off);

        $this->assertStringContainsString('Taking jobs', $html(true, now()->subMinutes(2)));
        $this->assertStringContainsString('On duty · no location', $html(true, now()->subDays(5)));
        $this->assertStringContainsString('On duty · no location', $html(true, null));

        // Customers keep the plain Online / Offline.
        $this->assertStringContainsString('Online', Blade::render('<x-ui.presence :user="$u" />', ['u' => $user->fresh()]));
    }
}
