<?php

namespace Tests\Feature\Food;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Livewire\Seller\Orders as SellerOrders;
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
 * Owner 2026-11-09: "if same restaurant has 10 orders and 6 riders are there, who will take what? Code would be
 * better." Every delivery order gets a 4-digit pickup code, unique among the restaurant's open orders. The restaurant
 * sees it (writes it on the bag / its bill), the rider sees it after reaching, says it at the counter, the restaurant
 * types it and taps "Handed over"; a wrong code is refused, and the rider cannot deliver before the hand-over.
 */
class PickupCodeTest extends TestCase
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

    private function order(string $type = 'delivery'): MarketplaceOrder
    {
        $dish = $this->makeProduct($this->restaurant, ['name' => 'Veg Meals', 'price' => 100, 'stock' => 9999, 'food_type' => 'veg']);
        app(CartService::class)->add($this->customer, $dish, 1);
        $extra = [];
        if ($type === 'delivery') {
            $address = $this->makeAddress($this->customer, $this->franchise, $this->zone);
            $extra['delivery_address_id'] = $address->id;
        }

        return app(CreateMarketplaceOrderAction::class)->execute(array_merge([
            'store_id' => $this->restaurant->id, 'customer_id' => $this->customer->id,
            'order_type' => $type, 'payment_method' => 'cash',
        ], $extra));
    }

    private function carriedBy(FieldWorker $rider, bool $ready = true): MarketplaceOrder
    {
        $order = $this->order();
        foreach ($ready ? ['accepted', 'preparing', 'ready'] : ['accepted', 'preparing'] as $to) {
            app(AdvanceMarketplaceOrderAction::class)->execute($order->id, $to, $to === 'accepted' ? 15 : null);
        }
        if (! $ready) {
            $order->forceFill(['rider_called_at' => now()])->save();
        }
        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $order->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $rider->id, 'status' => 'notified', 'notified_at' => now(),
        ]);
        app(AcceptMarketplaceDeliveryOfferAction::class)->execute($order->id, $rider);

        return $order->fresh();
    }

    public function test_every_delivery_order_gets_a_four_digit_code_unique_among_the_restaurants_open_orders(): void
    {
        $codes = collect(range(1, 12))->map(fn () => $this->order()->pickup_code);
        $codes->each(fn ($c) => $this->assertMatchesRegularExpression('/^\d{4}$/', $c));
        $this->assertSame(12, $codes->unique()->count());
        $this->assertNull($this->order('pickup')->pickup_code, 'collect-yourself orders need no rider code');

        // An order placed before codes existed gets one when it is first shown.
        $old = $this->order();
        $old->forceFill(['pickup_code' => null])->save();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $old->fresh()->ensurePickupCode());
        $this->assertNotContains($old->fresh()->pickup_code, $codes->all());
    }

    public function test_the_rider_says_the_code_and_the_restaurant_hands_over_only_on_the_right_code(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $rider->user->forceFill(['name' => 'Anees'])->save();
        $order = $this->carriedBy($rider);
        $code = $order->pickup_code;
        $wrong = $code === '1111' ? '2222' : '1111';

        // The restaurant sees the code from the start.
        $seller = fn () => Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'active');
        $seller()->assertSee('Pickup code')->assertSee($code);

        // The rider sees it only once he has reached, and cannot deliver before the hand-over.
        $rider_page = Livewire::actingAs($rider->user)->test(RiderDashboard::class)->assertDontSee('Tell this code at the counter')
            ->call('reachedStore')->assertSee('Tell this code at the counter')->assertSee($code)
            ->assertSee('Waiting for handover')
            ->set('shopOtp', $order->delivery_otp)->call('deliverShop')
            ->assertSet('error', 'Not handed over yet. Tell the pickup code.');

        // Wrong code: refused. Right code: handed over and recorded.
        $seller()->set("riderCodes.{$order->id}", $wrong)->call('handToRider', $order->id)
            ->assertSee('Wrong code for this order');
        $this->assertNull($order->fresh()->picked_up_at);
        $seller()->set("riderCodes.{$order->id}", $code)->call('handToRider', $order->id)
            ->assertSee('Handed over to Anees');
        $this->assertNotNull($order->fresh()->picked_up_at);
        $this->assertTrue($order->statusHistory()->where('note', 'Handed to rider Anees (pickup code checked)')->exists());

        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'active')
            ->assertSee('Picked up by Anees')->assertSee('Anees is delivering');

        Livewire::actingAs($rider->user)->test(RiderDashboard::class)->assertSee('Picked up ✓ Deliver now')
            ->set('shopOtp', $order->fresh()->delivery_otp)->call('deliverShop')->assertSet('notice', 'Delivered. Thank you!');
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_food_still_cooking_cannot_be_handed_over_and_another_shop_cannot_hand_it_over(): void
    {
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'parcel_rider');
        $order = $this->carriedBy($rider, ready: false);

        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'active')
            ->set("riderCodes.{$order->id}", $order->pickup_code)->call('handToRider', $order->id)
            ->assertSee('Mark ready first');
        $this->assertNull($order->fresh()->picked_up_at);

        $other = $this->makeStore($this->franchise, $this->zone, null, ['module' => Modules::FOOD, 'name' => 'Other Mess', 'fssai_number' => '98765432109876']);
        Livewire::actingAs($other->provider->user)->test(SellerOrders::class)
            ->set("riderCodes.{$order->id}", $order->pickup_code)->call('handToRider', $order->id)->assertNotFound();
        $this->assertNull($order->fresh()->picked_up_at);
    }
}
