<?php

namespace Tests\Feature\Food;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Actions\SetProviderOnlineStatusAction;
use App\Exceptions\CannotGoOfflineException;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Livewire\Seller\Dashboard as SellerDashboard;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\CartService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-09: a rider carrying a delivery and a service partner on the way to / working on a job cannot go
 * offline until it is done; a restaurant cannot close while it has orders in progress; the rider sees only the
 * restaurant and how far the customer is until he taps "Reached restaurant", then the order number, every
 * item, the customer and the delivery.
 */
class StayOnlineAndReachedStoreTest extends TestCase
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
        $this->customer->forceFill(['name' => 'Ravi Kumar'])->save();
        $this->restaurant = $this->makeStore($this->franchise, $this->zone, null, [
            'module' => Modules::FOOD, 'name' => 'Annapurna Mess', 'fssai_number' => '12345678901234',
            'delivery_fee_flat' => 30, 'prep_minutes' => 20, 'lat' => 1.0, 'lng' => 1.0,
        ]);
    }

    private function order(): MarketplaceOrder
    {
        $dish = $this->makeProduct($this->restaurant, ['name' => 'Chicken Biryani', 'price' => 200, 'stock' => 9999, 'food_type' => 'non_veg']);
        app(CartService::class)->add($this->customer, $dish, 2);
        $address = $this->makeAddress($this->customer, $this->franchise, $this->zone);
        $address->forceFill(['lat' => 1.0 + 3.2 / 111.19, 'lng' => 1.0, 'address_line' => '23-1-633 Mulapet'])->save();

        return app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $this->restaurant->id, 'customer_id' => $this->customer->id,
            'order_type' => 'delivery', 'payment_method' => 'cash', 'delivery_address_id' => $address->id,
        ]);
    }

    /** An order the rider has accepted, ready at the restaurant. */
    private function carried(FieldWorker $rider): MarketplaceOrder
    {
        $order = $this->order();
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            app(AdvanceMarketplaceOrderAction::class)->execute($order->id, $to, $to === 'accepted' ? 15 : null);
        }
        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $order->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $rider->id, 'status' => 'notified', 'notified_at' => now(),
        ]);
        app(AcceptMarketplaceDeliveryOfferAction::class)->execute($order->id, $rider);

        return $order->fresh();
    }

    public function test_a_rider_carrying_a_delivery_cannot_go_offline_until_it_is_delivered(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $order = $this->carried($rider);

        Livewire::actingAs($rider->user)->test(RiderDashboard::class)
            ->assertSee('Online till the delivery is done')
            ->assertSeeHtml('data-testid="online-switch-locked"')
            ->call('goOffline')
            ->assertSet('error', 'Finish the delivery to go offline.');
        $this->assertTrue($rider->fresh()->is_online);

        $order->forceFill(['rider_at_store_at' => now()])->save();
        app(CompleteMarketplaceOrderAction::class)->execute($order->id, $rider, $order->delivery_otp);

        Livewire::actingAs($rider->user)->test(RiderDashboard::class)->call('goOffline')->assertSet('error', '');
        $this->assertFalse($rider->fresh()->is_online);
    }

    public function test_a_service_partner_on_the_way_or_working_cannot_go_offline_but_a_scheduled_job_does_not_block(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $provider = $s['provider'];
        $provider->forceFill(['is_online' => true])->save();
        $action = app(SetProviderOnlineStatusAction::class);

        // Only scheduled: going offline is fine.
        $action->execute($provider->fresh(), false);
        $this->assertFalse($provider->fresh()->is_online);
        $provider->newQuery()->whereKey($provider->id)->update(['is_online' => true]);

        foreach (['provider_en_route', 'in_progress'] as $status) {
            $s['booking']->update(['status' => $status]);
            try {
                $action->execute($provider->fresh(), false);
                $this->fail("Went offline while {$status}");
            } catch (CannotGoOfflineException $e) {
                $this->assertSame('Finish the job to go offline.', $e->getMessage());
            }
            $this->assertTrue($provider->fresh()->is_online);
        }

        // The silent-location sweep leaves him online too.
        $provider->newQuery()->whereKey($provider->id)->update(['location_updated_at' => now()->subHours(5), 'updated_at' => now()->subHours(5)]);
        $this->artisan('providers:expire-stale-online')->assertSuccessful();
        $this->assertTrue($provider->fresh()->is_online);

        // Job done: offline is allowed again.
        $s['booking']->update(['status' => 'completed']);
        $action->execute($provider->fresh(), false);
        $this->assertFalse($provider->fresh()->is_online);
    }

    public function test_a_restaurant_cannot_close_while_an_order_is_in_progress(): void
    {
        $this->restaurant->forceFill(['is_open' => true])->save();
        $order = $this->order();
        $owner = $this->restaurant->provider->user;

        Livewire::actingAs($owner)->test(SellerDashboard::class)->call('toggleOpen')
            ->assertSet('notice', '1 order in progress. Close after delivery.');
        $this->assertTrue($this->restaurant->fresh()->is_open);

        $order->forceFill(['status' => 'completed'])->save();
        Livewire::actingAs($owner)->test(SellerDashboard::class)->call('toggleOpen');
        $this->assertFalse($this->restaurant->fresh()->is_open);
    }

    public function test_the_rider_sees_the_customer_and_items_only_after_reaching_the_restaurant(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $order = $this->carried($rider);

        $page = Livewire::actingAs($rider->user)->test(RiderDashboard::class)
            ->assertSee('Annapurna Mess')
            ->assertSee('Drop:')->assertSee('3.2 km')
            ->assertSee('Reached restaurant')
            ->assertDontSee('Ravi Kumar')->assertDontSee('23-1-633 Mulapet')->assertDontSee('Chicken Biryani')
            ->assertDontSeeHtml('id="shop-otp"');

        // Cannot mark it delivered before reaching the restaurant.
        $page->set('shopOtp', $order->delivery_otp)->call('deliverShop')
            ->assertSet('error', 'Tap "Reached restaurant" first.');
        $this->assertSame('ready', $order->fresh()->status);

        $page->call('reachedStore')
            ->assertSee('Tell this code at the counter')->assertSee($order->code)
            ->assertSee('Chicken Biryani')->assertSee('2 ×')
            ->assertSee('Ravi Kumar')->assertSee('23-1-633 Mulapet')
            ->assertDontSeeHtml('id="shop-otp"'); // the code box shows once the restaurant has handed it over
        $this->assertNotNull($order->fresh()->rider_at_store_at);
        $this->assertTrue($order->statusHistory()->where('note', 'Rider reached restaurant')->exists());

        // Owner 2026-11-09: the restaurant hands it over against the pickup code first.
        $page->assertSee($order->fresh()->pickup_code)
            ->set('shopOtp', $order->fresh()->delivery_otp)->call('deliverShop')
            ->assertSet('error', 'Not handed over yet. Tell the pickup code.');
        Livewire::actingAs($this->restaurant->provider->user)->test(\App\Livewire\Seller\Orders::class)->set('tab', 'active')
            ->set("riderCodes.{$order->id}", $order->fresh()->pickup_code)->call('handToRider', $order->id);
        Livewire::actingAs($rider->user)->test(RiderDashboard::class)->set('shopOtp', $order->fresh()->delivery_otp)->call('deliverShop')->assertSet('notice', 'Delivered. Thank you!');
        $this->assertSame('completed', $order->fresh()->status);
    }
}
