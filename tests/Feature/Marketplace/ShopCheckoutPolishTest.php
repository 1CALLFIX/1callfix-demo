<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\Customer\Shop\Bag;
use App\Livewire\Customer\Shop\Checkout;
use App\Models\Address;
use App\Models\MarketplaceOrder;
use App\Notifications\MarketplaceRecipientNotification;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Shop checkout polish (2026-11-02, from the owner's live walk-through):
 *  - a discounted item shows its discount in the bag and the summary, and "cash not available" names it;
 *  - checkout lists only the customer's own addresses (never parcel send-to addresses), default first;
 *  - a new address can be added inside checkout and is selected;
 *  - the shop's address shows on a delivery order;
 *  - "Send to someone else": name + phone on the order, shown to the customer and the rider, SMS with the code.
 */
class ShopCheckoutPolishTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores', 'address_line' => '12 Market Road', 'delivery_fee_flat' => 30]);
        $plain = $this->makeProduct($store, ['name' => 'Steel Bottle', 'price' => 100, 'stock' => 5]);
        $deal = $this->makeProduct($store, ['name' => 'Lunch Box', 'price' => 500, 'discount_percent' => 20, 'stock' => 5]);
        $customer = $this->makeCustomer();
        $this->w = compact('franchise', 'zone', 'store', 'plain', 'deal', 'customer') + ['address' => $this->makeAddress($customer, $franchise, $zone)];
    }

    private function checkout()
    {
        return Livewire::actingAs($this->w['customer'])->test(Checkout::class, ['store' => $this->w['store']]);
    }

    public function test_cash_off_names_the_discounted_item_and_the_discount_shows(): void
    {
        $cart = app(CartService::class);
        $cart->add($this->w['customer'], $this->w['plain'], 1);
        $cart->add($this->w['customer'], $this->w['deal'], 1);

        $this->checkout()
            ->assertViewHas('cashBlocked', true)
            ->assertSee('Not available: Lunch Box has an online-only price. Remove it or pay online.')
            ->assertSee('20% off · online')
            ->assertSee('₹500.00'); // the crossed-out price before the discount

        Livewire::actingAs($this->w['customer'])->test(Bag::class)->assertSee('20% off · online')->assertSee('₹500.00');
    }

    public function test_without_a_discounted_item_cash_is_on(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['plain'], 1);

        $this->checkout()->assertViewHas('cashBlocked', false)->assertDontSee('online-only price')
            ->set('paymentMethod', 'cash')->call('placeOrder')->assertHasNoErrors();
        $this->assertSame('cash', MarketplaceOrder::sole()->payment_method);
    }

    public function test_only_the_customers_own_addresses_are_listed_default_first(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['plain'], 1);
        $parcelDrop = Address::create([
            'user_id' => $this->w['customer']->id, 'franchise_id' => $this->w['franchise']->id, 'zone_id' => $this->w['zone']->id,
            'label' => 'Parcel drop — Ravi', 'lat' => 1, 'lng' => 1, 'address_line' => 'Ravi house, Nellore', 'is_parcel_only' => true,
        ]);
        $home = Address::create([
            'user_id' => $this->w['customer']->id, 'franchise_id' => $this->w['franchise']->id, 'zone_id' => $this->w['zone']->id,
            'label' => 'Home', 'lat' => 1, 'lng' => 1, 'address_line' => '4 Lake View, Nellore', 'is_default' => true,
        ]);

        $this->checkout()
            ->assertSet('addressId', $home->id)
            ->assertSee('4 Lake View, Nellore')
            ->assertDontSee('Ravi house, Nellore')
            ->assertSee('From Lakshmi Stores, 12 Market Road')
            ->set('addressId', $parcelDrop->id)->call('placeOrder')
            ->assertSet('error', 'Choose where to deliver, or pick it up at the shop.');
        $this->assertSame(0, MarketplaceOrder::count());
    }

    public function test_a_new_address_can_be_added_inside_checkout_and_is_selected(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['plain'], 1);

        $c = $this->checkout()->call('startAddAddress')
            ->set('newAddress.label', 'Office')->set('newAddress.address_line', '')->call('saveAddress')
            ->assertHasErrors('newAddress.address_line')
            ->set('newAddress.address_line', '8 Trunk Road, Nellore')->call('saveAddress')->assertHasNoErrors()
            ->assertSet('addingAddress', false);

        $office = Address::where('label', 'Office')->sole();
        $this->assertSame($this->w['customer']->id, $office->user_id);
        $this->assertSame($this->w['franchise']->id, $office->franchise_id);
        $this->assertFalse($office->is_parcel_only);
        $c->assertSet('addressId', $office->id)->set('paymentMethod', 'cash')->call('placeOrder')->assertHasNoErrors();
        $this->assertSame($office->id, MarketplaceOrder::sole()->delivery_address_id);
    }

    public function test_send_to_someone_else_saves_the_receiver_and_shows_it(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['plain'], 1);

        $this->checkout()->set('addressId', $this->w['address']->id)->set('paymentMethod', 'cash')
            ->set('sendToOther', true)->set('recipientName', '')->set('recipientPhone', '12')
            ->call('placeOrder')->assertHasErrors(['recipientName', 'recipientPhone']);
        $this->assertSame(0, MarketplaceOrder::count());

        $this->checkout()->set('addressId', $this->w['address']->id)->set('paymentMethod', 'cash')
            ->set('sendToOther', true)->set('recipientName', 'Ravi Kumar')->set('recipientPhone', '98480 12345')
            ->call('placeOrder')->assertHasNoErrors();

        $order = MarketplaceOrder::sole();
        $this->assertSame('Ravi Kumar', $order->recipient_name);
        $this->assertSame('9848012345', $order->recipient_phone);

        $this->actingAs($this->w['customer'])->get(route('customer.shop.orders.show', $order))
            ->assertOk()->assertSee('Receiver: Ravi Kumar, 9848012345');
    }

    public function test_pickup_never_keeps_a_receiver(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['plain'], 1);

        $this->checkout()->set('orderType', 'pickup')->set('paymentMethod', 'cash')
            ->set('sendToOther', true)->set('recipientName', 'Ravi')->set('recipientPhone', '9848012345')
            ->call('placeOrder')->assertHasNoErrors();
        $this->assertNull(MarketplaceOrder::sole()->recipient_name);
    }

    public function test_the_receiver_sms_carries_the_code_and_is_skipped_without_a_receiver(): void
    {
        Notification::fake();
        app(CartService::class)->add($this->w['customer'], $this->w['plain'], 1);
        $this->checkout()->set('addressId', $this->w['address']->id)->set('paymentMethod', 'cash')
            ->set('sendToOther', true)->set('recipientName', 'Ravi Kumar')->set('recipientPhone', '9848012345')
            ->call('placeOrder');
        $order = MarketplaceOrder::sole();
        $order->update(['delivery_otp' => '4321']);

        $sms = (new MarketplaceRecipientNotification($order->fresh()))->toSms(null);
        $this->assertStringContainsString('sent you an order from Lakshmi Stores', $sms);
        $this->assertStringContainsString('OTP 4321', $sms);

        $order->update(['recipient_name' => null, 'recipient_phone' => null]);
        MarketplaceRecipientNotification::sendFor($order->fresh());
        Notification::assertSentOnDemandTimes(MarketplaceRecipientNotification::class, 0);
    }
}
