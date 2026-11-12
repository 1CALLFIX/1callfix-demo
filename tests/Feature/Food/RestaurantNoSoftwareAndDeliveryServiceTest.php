<?php

namespace Tests\Feature\Food;

use App\Actions\CreateMarketplaceOrderAction;
use App\Livewire\Customer\Parcel\Create as SendParcel;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Notifications\FoodOrderWaitingNotification;
use App\Services\CartService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-12: restaurants with no billing software get a reminder before an order is cancelled and a printable
 * kitchen slip; and any restaurant / shop can book our rider for its own phone / WhatsApp order (prepaid balance,
 * parcel prices, prepaid food only — no cash on delivery).
 */
class RestaurantNoSoftwareAndDeliveryServiceTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    private $franchise;

    private $zone;

    private Store $restaurant;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->franchise, $this->zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($this->franchise, Modules::FOOD);
        $this->customer = $this->makeCustomer();
        $this->restaurant = $this->makeStore($this->franchise, $this->zone, null, [
            'module' => Modules::FOOD, 'name' => 'Annapurna Mess', 'fssai_number' => '12345678901234', 'prep_minutes' => 20,
            'address_line' => 'Trunk Road, Nellore', 'lat' => 14.44, 'lng' => 79.98, 'phone' => '9876500001',
        ]);
    }

    private function order(): MarketplaceOrder
    {
        $dish = $this->makeProduct($this->restaurant, ['name' => 'Veg Meals', 'price' => 100, 'stock' => 999, 'food_type' => 'veg']);
        app(CartService::class)->add($this->customer, $dish, 2, null, []);

        return app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $this->restaurant->id, 'customer_id' => $this->customer->id, 'order_type' => 'pickup', 'payment_method' => 'cash',
            'special_instructions' => 'Less spicy please',
        ]);
    }

    // ───────────────────────── reminder before a waiting order is cancelled ─────────────────────────

    public function test_a_waiting_order_reminds_the_owner_once_before_it_would_be_cancelled(): void
    {
        Notification::fake();
        $order = $this->order();
        $order->forceFill(['accept_by' => now()->addMinutes(7)])->save(); // 10-minute window, 3 minutes gone

        $this->artisan('food:expire-unaccepted')->assertSuccessful();
        $this->artisan('food:expire-unaccepted')->assertSuccessful();

        $owner = $this->restaurant->provider->user;
        Notification::assertSentToTimes($owner, FoodOrderWaitingNotification::class, 1);
        $this->assertNotNull($order->fresh()->accept_nudged_at);
        $this->assertSame('pending', $order->fresh()->status, 'Reminded, not cancelled.');
    }

    public function test_no_reminder_too_early_or_when_super_admin_switches_it_off(): void
    {
        Notification::fake();
        $order = $this->order();
        $order->forceFill(['accept_by' => now()->addMinutes(9)])->save(); // only 1 minute gone (reminder after 2)
        $this->artisan('food:expire-unaccepted');
        Notification::assertNotSentTo($this->restaurant->provider->user, FoodOrderWaitingNotification::class);

        Setting::set('food.nudge_after_minutes', '0');
        $order->forceFill(['accept_by' => now()->addMinutes(2)])->save();
        $this->artisan('food:expire-unaccepted');
        Notification::assertNotSentTo($this->restaurant->provider->user, FoodOrderWaitingNotification::class);
    }

    public function test_the_reminder_says_what_to_do(): void
    {
        $n = new FoodOrderWaitingNotification($this->order(), ['mail'], 7);
        $this->assertStringContainsString('Accept it in the next 7 min or it is cancelled', $n->toSms(null));
        $this->assertSame(route('seller.orders'), $n->pushLink(null));
    }

    // ───────────────────────── kitchen slip ─────────────────────────

    public function test_the_owner_prints_a_kitchen_slip_and_nobody_else_can(): void
    {
        $order = $this->order();

        $this->actingAs($this->restaurant->provider->user)->get(route('seller.orders.slip', $order))->assertOk()
            ->assertSee($order->code)->assertSee('2×')->assertSee('Veg Meals')->assertSee('Less spicy please')->assertSee('PICKUP');

        $this->actingAs($this->customer)->get(route('seller.orders.slip', $order))->assertForbidden();
    }

    public function test_the_orders_screen_links_to_the_slip(): void
    {
        $order = $this->order();
        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)
            ->assertSee(route('seller.orders.slip', [$order, 'print' => 1]), false);
    }

    // ───────────────────────── book a rider for the restaurant's own order ─────────────────────────

    public function test_book_a_rider_fills_in_the_restaurant_food_and_the_prepaid_balance(): void
    {
        $this->enableParcelModuleForTests();
        $this->activateParcelFor($this->franchise);
        $this->configureParcelPricing();
        $owner = $this->restaurant->provider->user;

        Livewire::actingAs($owner)->test(SellerOrders::class)->assertSee(route('customer.parcels.create', ['from' => 'store']), false);

        Livewire::actingAs($owner)->withQueryParams(['from' => 'store'])->test(SendParcel::class)
            ->assertSet('forStoreId', $this->restaurant->id)
            ->assertSet('pickupMode', 'pin')
            ->assertSet('pickupLat', 14.44)
            ->assertSet('pickupContactName', 'Annapurna Mess')
            ->assertSet('packageContents', 'food')
            ->assertSet('paymentMethod', 'wallet')
            ->assertSee('Book a rider for your own order')
            ->assertDontSee('Collect the item price from the recipient');
    }

    public function test_someone_without_a_shop_gets_the_normal_parcel_page(): void
    {
        $this->enableParcelModuleForTests();
        $this->activateParcelFor($this->franchise);
        $this->configureParcelPricing();

        Livewire::actingAs($this->customer)->withQueryParams(['from' => 'store'])->test(SendParcel::class)
            ->assertSet('forStoreId', null)
            ->assertDontSee('Book a rider for your own order');
    }
}
