<?php

namespace Tests\Feature\Marketplace;

use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Livewire\MarketplaceOrders\Settings as ShopSettings;
use App\Livewire\Partner\ApplyForm;
use App\Livewire\Provider\Auth\Register;
use App\Livewire\Seller\Orders;
use App\Livewire\Seller\Products;
use App\Livewire\Seller\Shop;
use App\Livewire\Stores\Manage as StoresManage;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Services\CartService;
use App\Services\WalletService;
use App\Support\MarketplaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * E-commerce Phase 1 (sellers, 2026-10-28): a seller joins through /partners → the one partner sign-up, the shop
 * waits for admin approval, and the seller runs products, orders and shop details from /seller. Owner rules proven
 * here: one join path; nothing sold before approval; free delivery and discounts are online-only benefits; a seller
 * rejection refunds in full with no fee; a pickup is handed over only with the customer's code.
 */
class SellerPortalTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->bindFakeFirebase();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.44, 'center_lng' => 79.98]);
        $this->activateMarketplaceModuleFor($franchise);
        $owner = $this->makeStoreOwner($franchise, $zone);
        $owner->user->forceFill(['password' => Hash::make('seller-pass-1')])->save();
        $store = $this->makeStore($franchise, $zone, $owner);
        $product = $this->makeProduct($store, ['price' => 100, 'stock' => 10]);
        $this->w = ['franchise' => $franchise, 'zone' => $zone->fresh(), 'owner' => $owner, 'store' => $store, 'product' => $product, 'customer' => $this->makeCustomer()];
    }

    private function sellerUser(): User
    {
        return $this->w['owner']->user;
    }

    private function order(string $method, array $extra = []): MarketplaceOrder
    {
        app(CartService::class)->add($this->w['customer'], $this->w['product'], 1);

        return app(CreateMarketplaceOrderAction::class)->execute(array_merge([
            'store_id' => $this->w['store']->id, 'customer_id' => $this->w['customer']->id, 'order_type' => 'pickup', 'payment_method' => $method,
        ], $extra));
    }

    // ── joining ─────────────────────────────────────────────────────────

    public function test_seller_or_shop_is_a_waiting_list_until_super_admin_opens_seller_sign_ups(): void
    {
        Livewire::test(ApplyForm::class)->set('role', 'commerce')->set('name', 'Lakshmi Stores')->set('phone', '9876500011')
            ->set('cityChoice', '__other')->set('city', 'Nellore')->set('consent', true)->call('submit')->assertSet('outcome', 'waitlist');

        Setting::set('marketplace.recruiting_sellers', '1');
        Livewire::test(ApplyForm::class)->set('role', 'commerce')->set('name', 'Lakshmi Stores')->set('phone', '9876500012')
            ->set('cityChoice', '__other')->set('city', 'Nellore')->set('consent', true)->call('submit')->assertSet('outcome', 'handoff');
    }

    public function test_a_new_seller_signs_up_with_otp_and_documents_and_the_shop_waits_for_approval(): void
    {
        Setting::set('marketplace.recruiting_sellers', '1');
        // The one join path: /partners first, then the sign-up continues that lead.
        Livewire::test(ApplyForm::class)->set('role', 'commerce')->set('name', 'Lakshmi')->set('phone', '9876500013')
            ->set('cityChoice', '__other')->set('city', 'Nellore')->set('consent', true)->call('submit')->assertSet('outcome', 'handoff');

        $c = Livewire::test(Register::class)->assertSet('partnerRole', 'commerce')
            ->set('phone', '9876500013')->call('requestPhoneCode')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164('9876500013')))->assertSet('step', 'details');
        $c->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('name', 'Lakshmi')->set('password', 'shop-pass-1')->set('password_confirmation', 'shop-pass-1')
            ->set('address', '12 Main Bazaar, Nellore')->set('terms', true)
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->call('submitApplication')->assertHasErrors('storeName')
            ->set('storeName', 'Lakshmi General Stores')->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $user = User::where('phone', '9876500013')->sole();
        $store = Store::where('name', 'Lakshmi General Stores')->sole();
        $this->assertSame('pending', $store->approval_status);
        $this->assertFalse($store->is_active);
        $this->assertSame('lakshmi-general-stores', $store->slug);
        $this->assertSame($user->id, $store->provider->user_id);
        $this->assertSame('pending', $store->provider->kyc_status);
        $this->assertEmpty($store->provider->skills, 'a seller is never offered service jobs');
        $this->assertSame(3, $store->provider->documents()->count());
        $this->assertTrue($user->holdsPartnerRole('commerce'));
        $this->assertFalse($user->fresh()->canApplyAsPartner('commerce'), 'one shop per account through sign-up');

        // Nothing is sold before approval: hidden from the shop API and refused at checkout.
        $this->getJson('/api/stores')->assertOk()->assertJsonMissing(['name' => 'Lakshmi General Stores']);
        $product = Product::create(['store_id' => $store->id, 'name' => 'Rice 5kg', 'price' => 300, 'stock' => 5, 'is_active' => true, 'is_approved' => true]);
        app(CartService::class)->add($this->w['customer'], $product, 1);
        $this->expectExceptionMessage('This store is not taking orders.');
        app(CreateMarketplaceOrderAction::class)->execute(['store_id' => $store->id, 'customer_id' => $this->w['customer']->id, 'order_type' => 'pickup', 'payment_method' => 'online']);
    }

    public function test_a_service_partner_opens_a_shop_on_the_same_provider_account(): void
    {
        Setting::set('marketplace.recruiting_sellers', '1');
        $provider = Provider::create(['user_id' => $this->makeCustomer()->id, 'franchise_id' => $this->w['franchise']->id, 'zone_id' => $this->w['zone']->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'skills' => [1]]);
        $user = $provider->user;
        $user->forceFill(['role' => 'provider', 'phone_verified_at' => now()])->save();
        Livewire::actingAs($user->fresh())->test(ApplyForm::class)->set('role', 'commerce')->set('name', 'Ravi')->set('phone', $user->phone)
            ->set('cityChoice', '__other')->set('city', 'Nellore')->set('consent', true)->call('submit')->assertSet('outcome', 'handoff');

        Livewire::actingAs($user->fresh())->test(Register::class)
            ->call('requestPhoneCode')->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($user->phone)))
            ->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('storeName', 'Ravi Electricals')->set('address', 'Shop 4, Trunk Road')->set('terms', true)
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $this->assertSame(1, Provider::where('user_id', $user->id)->count(), 'no second provider profile');
        $this->assertSame($provider->id, Store::where('name', 'Ravi Electricals')->sole()->provider_id);
        $this->assertTrue($user->fresh()->holdsPartnerRole('service'));
        $this->assertTrue($user->fresh()->holdsPartnerRole('commerce'));
    }

    // ── admin approval ──────────────────────────────────────────────────

    public function test_admin_approves_a_shop_only_after_the_documents_and_can_send_it_back_with_a_note(): void
    {
        $this->w['store']->update(['approval_status' => 'pending', 'is_active' => false]);
        $this->w['owner']->update(['kyc_status' => 'pending']);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(StoresManage::class)->call('approveStore', $this->w['store']->id);
        $this->assertSame('pending', $this->w['store']->fresh()->approval_status, 'documents not approved yet');

        $this->w['owner']->update(['kyc_status' => 'approved']);
        Livewire::actingAs($admin)->test(StoresManage::class)
            ->call('startRejectStore', $this->w['store']->id)->call('rejectStore')->assertHasErrors('rejectNote')
            ->set('rejectNote', 'Shop photo is missing')->call('rejectStore')->assertHasNoErrors();
        $this->assertSame('rejected', $this->w['store']->fresh()->approval_status);
        $this->actingAs($this->sellerUser())->get(route('seller.dashboard'))->assertOk()->assertSee('Shop photo is missing');

        Livewire::actingAs($admin)->test(StoresManage::class)->call('approveStore', $this->w['store']->id);
        $store = $this->w['store']->fresh();
        $this->assertTrue($store->isSellable());
        $this->assertSame($admin->id, $store->approved_by);
    }

    // ── portal access ───────────────────────────────────────────────────

    public function test_the_portal_is_for_sellers_only_and_guests_go_to_the_seller_sign_in(): void
    {
        $this->get(route('seller.orders'))->assertRedirect(route('seller.login'));
        $this->actingAs($this->w['customer'])->get(route('seller.dashboard'))->assertForbidden();
        foreach (['seller.dashboard', 'seller.orders', 'seller.products', 'seller.shop', 'seller.earnings', 'seller.payment-accounts', 'seller.request-payout'] as $route) {
            $this->actingAs($this->sellerUser())->get(route($route))->assertOk();
        }
    }

    // ── products ────────────────────────────────────────────────────────

    public function test_a_new_product_waits_for_approval_and_blocked_words_are_refused(): void
    {
        Setting::set('marketplace.blocked_product_words', "medicine\nalcohol");

        Livewire::actingAs($this->sellerUser())->test(Products::class)->call('startCreate')
            ->set('name', 'Cough Medicine')->set('price', '50')->set('stock', '5')->call('save')->assertHasErrors('name')
            ->set('name', 'Steel Water Bottle')->set('uploads', [UploadedFile::fake()->image('bottle.jpg')])->call('save')->assertHasNoErrors();

        $product = Product::where('name', 'Steel Water Bottle')->sole();
        $this->assertFalse($product->is_approved, 'approval is on by default');
        $this->assertSame('steel-water-bottle', $product->slug);
        $this->assertCount(1, $product->images);
        Storage::disk('public')->assertExists($product->images[0]);
        $this->assertFalse(Product::where('name', 'Cough Medicine')->exists());
    }

    public function test_a_price_or_stock_change_stays_live_but_a_new_name_needs_approval_again(): void
    {
        $p = $this->w['product'];
        Livewire::actingAs($this->sellerUser())->test(Products::class)->call('edit', $p->id)->set('price', '120')->set('stock', '7')->call('save')->assertHasNoErrors();
        $this->assertTrue($p->fresh()->is_approved);
        $this->assertEquals(120, $p->fresh()->price);

        Livewire::actingAs($this->sellerUser())->test(Products::class)->call('edit', $p->id)->set('name', 'Renamed thing')->call('save');
        $this->assertFalse($p->fresh()->is_approved);
    }

    public function test_a_seller_cannot_touch_another_shops_products_or_orders(): void
    {
        $other = $this->makeStore($this->w['franchise'], $this->w['zone']);
        $theirs = $this->makeProduct($other);
        $theirOrder = $this->makeMarketplaceOrderScenario('pending', ['payment_method' => 'cash'])['order'];

        Livewire::actingAs($this->sellerUser())->test(Products::class)->call('edit', $theirs->id)->assertNotFound();
        Livewire::actingAs($this->sellerUser())->test(Products::class)->call('setStock', $theirs->id, '0')->assertNotFound();
        Livewire::actingAs($this->sellerUser())->test(Orders::class)->call('advance', $theirOrder->id, 'accepted')->assertNotFound();
        $this->assertSame('pending', $theirOrder->fresh()->status);
    }

    // ── orders ──────────────────────────────────────────────────────────

    public function test_the_seller_is_told_of_a_new_order_only_once_it_is_theirs_to_accept(): void
    {
        Notification::fake();
        $online = $this->order('online');
        Notification::assertNotSentTo($this->sellerUser(), MarketplaceOrderStatusNotification::class);

        $this->order('cash');
        Notification::assertSentTo($this->sellerUser(), MarketplaceOrderStatusNotification::class, fn ($n) => $n->eventKey() === 'marketplace_order.seller_new');
        $this->assertTrue($online->isAwaitingOnlinePayment());
    }

    public function test_an_unpaid_online_order_cannot_be_accepted_from_the_portal(): void
    {
        $order = $this->order('online');
        Livewire::actingAs($this->sellerUser())->test(Orders::class)->call('advance', $order->id, 'accepted')->assertSet('flashType', 'error');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_a_pickup_order_is_packed_and_handed_over_only_with_the_customers_code(): void
    {
        $order = $this->order('cash');
        $c = Livewire::actingAs($this->sellerUser())->test(Orders::class)
            ->call('advance', $order->id, 'accepted')->call('advance', $order->id, 'preparing')->call('advance', $order->id, 'ready');

        $code = $order->fresh()->delivery_otp;
        $this->assertMatchesRegularExpression('/^\d{4,}$/', (string) $code, 'collection code made at ready');

        $c->set('codes.'.$order->id, '0000'.($code === '0000' ? '1' : ''))->call('handOver', $order->id)->assertSet('flashType', 'error');
        $this->assertSame('ready', $order->fresh()->status);

        $c->set('codes.'.$order->id, $code)->call('handOver', $order->id)->assertSet('flashType', 'success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->settlement, 'settled like any completed order');
    }

    public function test_a_seller_rejection_refunds_a_wallet_order_in_full_with_no_fee_and_returns_the_stock(): void
    {
        // A fee that would apply to an admin cancel — never to the shop's own rejection.
        Setting::create(['key' => 'payment.wallet_enabled', 'value' => '1']);
        Setting::create(['key' => 'cancellation.free_minutes', 'value' => '0']);
        Setting::create(['key' => 'cancellation.fee_type', 'value' => 'flat']);
        Setting::create(['key' => 'cancellation.fee_value', 'value' => '50']);
        $wallet = app(WalletService::class);
        Wallet::updateOrCreate(['user_id' => $this->w['customer']->id], ['balance' => 500]);
        $order = $this->order('wallet');
        $this->assertEquals(400, $wallet->balance($this->w['customer']));
        $this->assertSame(9, $this->w['product']->fresh()->stock);

        Livewire::actingAs($this->sellerUser())->test(Orders::class)
            ->call('startReject', $order->id)->call('reject')->assertHasErrors('rejectReason')
            ->set('rejectReason', 'Out of stock')->call('reject')->assertHasNoErrors();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertEquals(0, $order->cancellation_fee);
        $this->assertEquals(500, $wallet->balance($this->w['customer']), 'refunded in full');
        $this->assertSame(10, $this->w['product']->fresh()->stock);
    }

    public function test_the_seller_cannot_cancel_once_the_order_is_ready(): void
    {
        $order = $this->order('cash');
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            app(AdvanceMarketplaceOrderAction::class)->execute($order->id, $to);
        }
        Livewire::actingAs($this->sellerUser())->test(Orders::class)
            ->call('startReject', $order->id)->set('rejectReason', 'Changed my mind')->call('reject')->assertSet('flashType', 'error');
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_a_closed_shop_takes_no_orders(): void
    {
        $this->w['store']->update(['is_open' => false]);
        $this->expectExceptionMessage('This store is closed right now.');
        $this->order('cash');
    }

    // ── shop details and settings ───────────────────────────────────────

    public function test_the_seller_sets_the_order_terms_and_free_delivery_stays_online_only(): void
    {
        Livewire::actingAs($this->sellerUser())->test(Shop::class)
            ->set('deliveryFee', '30')->set('freeDeliveryAbove', '50')->set('minimumOrder', '0')->call('save')->assertHasNoErrors();
        $this->assertEquals(30, $this->w['store']->fresh()->delivery_fee_flat);

        $address = $this->makeAddress($this->w['customer'], $this->w['franchise'], $this->w['zone']);
        $cash = $this->order('cash', ['order_type' => 'delivery', 'delivery_address_id' => $address->id]);
        $this->assertEquals(30, $cash->delivery_fee, 'cash gets no free delivery');
        $online = $this->order('online', ['order_type' => 'delivery', 'delivery_address_id' => $address->id]);
        $this->assertEquals(0, $online->delivery_fee);
    }

    public function test_shop_settings_are_super_admin_only_validated_and_audit_logged(): void
    {
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(ShopSettings::class)
            ->set('inputs.marketplace__return_window_days', '99')->call('save')->assertHasErrors('inputs.marketplace__return_window_days')
            ->set('inputs.marketplace__return_window_days', '5')->set('inputs.marketplace__recruiting_sellers', '1')->call('save')->assertHasNoErrors();

        $this->assertSame('5', MarketplaceSettings::get('marketplace.return_window_days'));
        $this->assertTrue(MarketplaceSettings::recruitingSellers());
        $this->assertTrue(\App\Models\ActivityLog::where('description', 'like', '%marketplace.return_window_days%')->exists());

        $this->actingAs($this->sellerUser())->get(route('admin.marketplace-orders.settings'))->assertForbidden();
    }
}
