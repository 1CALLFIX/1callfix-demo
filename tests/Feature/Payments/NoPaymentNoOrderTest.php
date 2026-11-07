<?php

namespace Tests\Feature\Payments;

use App\Actions\CreateMarketplaceOrderAction;
use App\Actions\CreateParcelOrderAction;
use App\Livewire\Customer\Parcel\Index as MyParcels;
use App\Livewire\Customer\Parcel\Show as ParcelShow;
use App\Livewire\Customer\Shop\OrderShow as ShopOrderShow;
use App\Livewire\Customer\Shop\Orders as MyShopOrders;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\CartItem;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\ParcelOrderStatusNotification;
use App\Services\CartService;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner rule 2026-11-07 (Meesho way): NO PAYMENT, NO ORDER for shop, food and parcel. An online order is only a
 * payment attempt until Razorpay captures it: the shop / restaurant never sees it, the customer's lists don't show
 * it, the cart is kept, ordering again replaces it, and it ends quietly. The capture makes it the order.
 */
class NoPaymentNoOrderTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    private function shop(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $owner = $this->makeStoreOwner($franchise, $zone);
        $store = $this->makeStore($franchise, $zone, $owner);
        $product = $this->makeProduct($store, ['price' => 100, 'stock' => 10]);

        return compact('franchise', 'zone', 'customer', 'owner', 'store', 'product');
    }

    private function order(array $w, string $method): MarketplaceOrder
    {
        if (CartItem::where('user_id', $w['customer']->id)->doesntExist()) {
            app(CartService::class)->add($w['customer'], $w['product'], 2);
        }

        return app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $w['store']->id, 'customer_id' => $w['customer']->id, 'order_type' => 'pickup', 'payment_method' => $method,
        ]);
    }

    private function capture(array $link, float $amount): void
    {
        $p = Payment::create($link + ['amount' => $amount, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending']);
        app(RazorpayWebhookHandler::class)->handleCaptured(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'order_id' => $p->gateway_order_id, 'id' => 'pay_'.Str::random(8), 'currency' => 'INR', 'amount' => (int) round($amount * 100),
        ]]]]);
    }

    public function test_an_unpaid_online_shop_order_is_not_an_order_for_anyone_and_the_cart_is_kept(): void
    {
        Notification::fake();
        $w = $this->shop();

        $attempt = $this->order($w, 'online');

        $this->assertSame(1, CartItem::where('user_id', $w['customer']->id)->count(), 'cart kept until paid');
        $this->assertSame(0, MarketplaceOrder::booked()->count());
        Notification::assertNotSentTo($w['customer'], MarketplaceOrderStatusNotification::class);
        Livewire::actingAs($w['customer'])->test(MyShopOrders::class)->assertDontSee($attempt->code);
        Livewire::actingAs($w['owner']->user)->test(SellerOrders::class)->assertDontSee($attempt->code);
        Livewire::actingAs($w['customer'])->test(ShopOrderShow::class, ['order' => $attempt])
            ->assertSee('Complete your payment')
            ->assertSee('Your order is placed only after the payment')
            ->assertDontSeeHtml('grid-cols-5'); // no "Order placed" progress bar
    }

    public function test_the_payment_makes_it_the_order_empties_the_cart_and_tells_the_shop(): void
    {
        Notification::fake();
        $w = $this->shop();
        $attempt = $this->order($w, 'online');

        $this->capture(['marketplace_order_id' => $attempt->id, 'purpose' => 'marketplace_order'], (float) $attempt->total_amount);

        $this->assertSame('paid', $attempt->fresh()->payment_status);
        $this->assertSame(0, CartItem::where('user_id', $w['customer']->id)->count());
        Notification::assertSentTo($w['customer'], MarketplaceOrderStatusNotification::class);
        Livewire::actingAs($w['owner']->user)->test(SellerOrders::class)->assertSee($attempt->code);
    }

    public function test_ordering_again_with_cash_replaces_the_unpaid_order_and_puts_its_stock_back(): void
    {
        Notification::fake();
        $w = $this->shop();
        $attempt = $this->order($w, 'online');
        $this->assertSame(8, (int) $w['product']->fresh()->stock);

        $cash = $this->order($w, 'cash'); // back from the payment window, same cart, cash

        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->assertSame(8, (int) $w['product']->fresh()->stock, 'old 2 back, new 2 taken: never both');
        $this->assertSame([$cash->id], MarketplaceOrder::booked()->pluck('id')->all());
        // Only the cash order's "placed" message: nothing about the replaced unpaid one.
        Notification::assertSentToTimes($w['customer'], MarketplaceOrderStatusNotification::class, 1);
    }

    public function test_an_unpaid_shop_order_expires_quietly_and_says_no_order_was_made(): void
    {
        Notification::fake();
        $w = $this->shop();
        $attempt = $this->order($w, 'online');
        $attempt->forceFill(['created_at' => now()->subHours(2)])->save();

        $this->artisan('marketplace:expire-unpaid')->assertSuccessful();

        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->assertSame(0.0, (float) $attempt->fresh()->cancellation_fee);
        Notification::assertNotSentTo($w['customer'], MarketplaceOrderStatusNotification::class);
        Livewire::actingAs($w['customer'])->test(ShopOrderShow::class, ['order' => $attempt->fresh()])
            ->assertSee('Payment not completed')->assertSee('No order was made')->assertDontSeeHtml('data-testid="attempt-pay"');
    }

    public function test_a_late_payment_on_an_ended_shop_order_never_revives_it(): void
    {
        \Illuminate\Support\Facades\Http::fake(['api.razorpay.com/*' => \Illuminate\Support\Facades\Http::response(['id' => 'rfnd_1', 'status' => 'processed', 'amount' => 1], 200)]);
        $w = $this->shop();
        $attempt = $this->order($w, 'online');
        $attempt->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->artisan('marketplace:expire-unpaid');

        $this->capture(['marketplace_order_id' => $attempt->id, 'purpose' => 'marketplace_order'], (float) $attempt->total_amount);

        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->assertNotSame('paid', $attempt->fresh()->payment_status, 'never an active, paid order');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/refund')); // the late money goes back
    }

    private function parcelWorld(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff');
    }

    private function parcel(array $w, string $method): ParcelOrder
    {
        return app(CreateParcelOrderAction::class)->execute(['restricted_items_confirmed' => true,
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_name' => 'Ravi', 'recipient_phone' => '9876543210', 'payment_method' => $method,
        ]);
    }

    public function test_an_unpaid_online_parcel_is_not_an_order_and_booking_again_replaces_it(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->parcelWorld();

        $attempt = $this->parcel($w, 'online');

        Notification::assertNotSentTo($w['customer'], ParcelOrderStatusNotification::class);
        Livewire::actingAs($w['customer'])->test(MyParcels::class)->assertDontSee($attempt->code);
        Livewire::actingAs($w['customer'])->test(ParcelShow::class, ['parcelOrder' => $attempt])
            ->assertSee('Complete your payment')->assertSee('Your parcel order is placed only after the payment');

        $cash = $this->parcel($w, 'cash');

        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->assertSame([$cash->id], ParcelOrder::booked()->pluck('id')->all());
        // Only the cash order's "booked" message: nothing about the replaced unpaid one.
        Notification::assertSentToTimes($w['customer'], ParcelOrderStatusNotification::class, 1);
    }

    public function test_an_unpaid_parcel_expires_quietly(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->parcelWorld();
        $attempt = $this->parcel($w, 'online');
        $attempt->forceFill(['created_at' => now()->subHours(2)])->save();

        $this->artisan('parcel:expire-unpaid')->assertSuccessful();

        $this->assertSame('cancelled', $attempt->fresh()->status);
        Notification::assertNotSentTo($w['customer'], ParcelOrderStatusNotification::class);
        Livewire::actingAs($w['customer'])->test(ParcelShow::class, ['parcelOrder' => $attempt->fresh()])->assertSee('No parcel order was made');
    }

    public function test_the_payment_makes_the_parcel_the_order(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->parcelWorld();
        $attempt = $this->parcel($w, 'online');

        $this->capture(['parcel_order_id' => $attempt->id, 'purpose' => 'parcel_order'], (float) $attempt->amountDue());

        $this->assertSame('paid', $attempt->fresh()->payment_status);
        Notification::assertSentTo($w['customer'], ParcelOrderStatusNotification::class);
        Livewire::actingAs($w['customer'])->test(MyParcels::class)->assertSee($attempt->code);
    }
}
