<?php

namespace Tests\Feature\Food;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Jobs\MarketplaceDispatchJob;
use App\Livewire\MarketplaceOrders\Manage as MarketplaceOrdersManage;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Notifications\MarketplaceNoRiderNotification;
use App\Notifications\RiderShopOfferNotification;
use App\Services\CartService;
use App\Services\DispatchService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-09: "booked a food order but got only 1 ring on rider app, and there is no option for the restaurant
 * to assign it to a local rider". Proven here: every food / shop offer pushes to the rider's phone; a rider who only
 * missed an offer is offered it again next round, one who declined is not; when the rounds run out the restaurant
 * (and admins) are told; the restaurant and an admin can ring a nearby rider by hand, who then accepts as usual.
 */
class FoodRiderRingTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
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

    private function readyOrder(string $status = 'ready'): MarketplaceOrder
    {
        $dish = $this->makeProduct($this->restaurant, ['name' => 'Veg Meals', 'price' => 100, 'stock' => 9999, 'food_type' => 'veg']);
        app(CartService::class)->add($this->customer, $dish, 1);
        $address = $this->makeAddress($this->customer, $this->franchise, $this->zone);
        $address->forceFill(['lat' => 1.01, 'lng' => 1.0])->save();
        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $this->restaurant->id, 'customer_id' => $this->customer->id,
            'order_type' => 'delivery', 'payment_method' => 'cash', 'delivery_address_id' => $address->id,
        ]);
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted', 15);
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'preparing');
        if ($status === 'ready') {
            app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'ready');
        }

        return $order->fresh();
    }

    private function runRound(MarketplaceOrder $order, int $round): void
    {
        (new MarketplaceDispatchJob($order->id, $round))->handle(app(DispatchService::class));
    }

    private function offers(MarketplaceOrder $order, FieldWorker $rider): \Illuminate\Support\Collection
    {
        return DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)->where('dispatchable_id', $order->id)
            ->where('notifiable_id', $rider->id)->orderBy('id')->pluck('status');
    }

    public function test_every_offer_rings_the_phone_and_a_rider_who_missed_it_is_offered_again(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $order = $this->readyOrder();

        $this->runRound($order, 1);
        Notification::assertSentTo($rider->user, RiderShopOfferNotification::class);
        $this->assertSame(['notified'], $this->offers($order, $rider)->all());

        // Still ringing: not offered twice at once.
        $this->runRound($order, 2);
        $this->assertCount(1, $this->offers($order, $rider));

        // Missed (25 s passed): offered again, and the phone rings again.
        $this->travel(26)->seconds();
        $this->runRound($order, 3);
        $this->assertSame(['timeout', 'notified'], $this->offers($order, $rider)->all());
        Notification::assertSentToTimes($rider->user, RiderShopOfferNotification::class, 2);

        // He can accept the new offer.
        app(AcceptMarketplaceDeliveryOfferAction::class)->execute($order->id, $rider);
        $this->assertSame($rider->id, $order->fresh()->assigned_worker_id);
    }

    public function test_a_rider_who_declined_is_not_offered_it_again(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $order = $this->readyOrder();
        $this->runRound($order, 1);
        DispatchAttempt::where('dispatchable_id', $order->id)->update(['status' => 'rejected']);

        $this->travel(26)->seconds();
        $this->runRound($order, 2);
        $this->assertSame(['rejected'], $this->offers($order, $rider)->all());
    }

    public function test_when_no_rider_accepts_the_restaurant_and_admins_are_told(): void
    {
        Setting::set('marketplace.dispatch.max_rounds', '2');
        $order = $this->readyOrder();
        $admin = $this->makeSuperAdmin();
        $admin->forceFill(['push_ops_alerts' => true, 'fcm_token' => 'test-token'])->save();

        $this->runRound($order, 3); // past the last round

        Notification::assertSentTo($this->restaurant->provider->user, MarketplaceNoRiderNotification::class);
        Notification::assertSentTo($admin, MarketplaceNoRiderNotification::class);
        $this->assertTrue($order->statusHistory()->where('note', 'like', 'No rider accepted%')->exists());
    }

    public function test_the_restaurant_picks_a_nearby_rider_who_then_accepts(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $rider->user->forceFill(['name' => 'Anees'])->save();
        $order = $this->readyOrder();
        // He already declined once: the restaurant may still ring him (they may have phoned him).
        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $order->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $rider->id, 'status' => 'rejected', 'notified_at' => now(),
        ]);

        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'active')
            ->assertSee('Pick a rider')
            ->call('togglePicker', $order->id)->assertSee('Anees')->assertSee('km away')
            ->call('ringRider', $order->id, $rider->id)->assertSee('Ringing Anees');

        Notification::assertSentTo($rider->user, RiderShopOfferNotification::class);
        $this->assertTrue($order->statusHistory()->where('note', 'Restaurant asked rider Anees to deliver')->exists());
        app(AcceptMarketplaceDeliveryOfferAction::class)->execute($order->id, $rider);
        $this->assertSame($rider->id, $order->fresh()->assigned_worker_id);

        // Assigned: no more picking.
        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'active')
            ->assertDontSee('Pick a rider')
            ->call('ringRider', $order->id, $rider->id)->assertSee('already has a rider');
    }

    public function test_picking_while_cooking_calls_the_rider_now_and_a_far_or_offline_rider_cannot_be_rung(): void
    {
        $near = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $offline = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $offline->forceFill(['is_online' => false])->save();
        $order = $this->readyOrder('preparing');
        $this->assertNull($order->rider_called_at);

        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'active')
            ->call('ringRider', $order->id, $offline->id)->assertSee('no longer free nearby')
            ->call('ringRider', $order->id, $near->id)->assertSee('Ringing');

        $this->assertNotNull($order->fresh()->rider_called_at);
        app(AcceptMarketplaceDeliveryOfferAction::class)->execute($order->id, $near);
        $this->assertSame($near->id, $order->fresh()->assigned_worker_id);
    }

    public function test_another_shop_cannot_ring_a_rider_for_this_order(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $order = $this->readyOrder();
        $other = $this->makeStore($this->franchise, $this->zone, null, ['module' => Modules::FOOD, 'name' => 'Other Mess', 'fssai_number' => '98765432109876']);

        Livewire::actingAs($other->provider->user)->test(SellerOrders::class)
            ->call('ringRider', $order->id, $rider->id)->assertNotFound();
        $this->assertCount(0, $this->offers($order, $rider));
        Notification::assertNothingSentTo($rider->user);
    }

    public function test_an_admin_rings_a_rider_from_the_order_screen(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $rider->user->forceFill(['name' => 'Anees'])->save();
        $order = $this->readyOrder();

        Livewire::actingAs($this->makeSuperAdmin())->test(MarketplaceOrdersManage::class)
            ->call('viewOrder', $order->id)
            ->assertSee('Pick a rider')->assertSee('Anees')
            ->call('ringRider', $rider->id)->assertHasNoErrors();

        Notification::assertSentTo($rider->user, RiderShopOfferNotification::class);
        $this->assertTrue($order->statusHistory()->where('note', 'Admin asked rider Anees to deliver')->exists());
    }
}
