<?php

namespace Tests\Feature\Marketplace;

use App\Actions\CompleteMarketplaceOrderAction;
use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Shop\OrderShow;
use App\Livewire\MarketplaceReturns\Index as ReturnsQueue;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceReturn;
use App\Models\MarketplaceSettlement;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketplace\MarketplaceReturnService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * E-commerce Phase 3b (shop returns, 2026-10-29). Owner rules: within the return window, damaged or wrong item only,
 * with a photo; the refund follows the MANUAL MONEY ACTIONS approval model (permission, scope, limits, maker-checker,
 * escalation, reason, audit, idempotent); online orders refund to the original payment, wallet/cash to the wallet;
 * the seller's earnings stay held while a return is open and the refund comes out of them.
 */
class ShopReturnTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** A completed pickup order (goods 200), settled; online orders get a captured Razorpay payment. */
    private function completed(string $method = 'wallet'): array
    {
        $s = $this->makeMarketplaceOrderScenario('ready', [
            'order_type' => 'pickup', 'subtotal' => 200, 'total_amount' => 200,
            'payment_method' => $method, 'payment_status' => $method === 'cash' ? 'pending' : 'paid',
        ]);
        $s['order']->items()->create(['product_id' => $s['product']->id, 'product_name_snapshot' => 'Bottle', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        if ($method === 'online') {
            Payment::create(['marketplace_order_id' => $s['order']->id, 'purpose' => 'marketplace_order', 'amount' => 200, 'gateway' => 'razorpay',
                'gateway_order_id' => 'order_'.Str::random(6), 'gateway_payment_id' => 'pay_RET1', 'status' => 'captured', 'captured_at' => now()]);
        }
        app(CompleteMarketplaceOrderAction::class)->execute($s['order']->id);
        $s['order'] = $s['order']->fresh('items');

        return $s;
    }

    private function ask(array $s): MarketplaceReturn
    {
        $item = $s['order']->items->first();

        return app(MarketplaceReturnService::class)->request($s['order'], $s['customer'], 'damaged', 'The bottle is dented on one side.', [$item->id => 1], ['returns/x.jpg']);
    }

    private function franchiseRefunder(array $s, string $permission = MarketplaceReturnService::REFUND): User
    {
        $user = $this->makeCustomer();
        $user->forceFill(['role' => 'franchise_owner'])->save();
        $role = Role::create(['name' => 'Returns '.Str::random(4), 'slug' => 'returns-'.Str::lower(Str::random(6))]);
        $role->permissions()->attach(Permission::where('slug', $permission)->value('id'));
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'franchise', 'scope_id' => $s['franchise']->id]);

        return $user->fresh();
    }

    // ── asking ──────────────────────────────────────────────────────────

    public function test_the_customer_asks_from_the_order_page_with_a_photo_within_the_window(): void
    {
        $s = $this->completed('online');
        $item = $s['order']->items->first();
        Livewire::actingAs($s['customer'])->test(OrderShow::class, ['order' => $s['order']])
            ->assertViewHas('canReturn', true)
            ->set('returnReason', 'wrong_item')->set('returnDescription', 'I ordered steel, this is plastic.')->set("returnQty.{$item->id}", 1)
            ->call('requestReturn')->assertHasErrors('returnPhotos')
            ->set('returnPhotos', [UploadedFile::fake()->image('wrong.jpg')])->call('requestReturn')->assertHasNoErrors();

        $r = MarketplaceReturn::sole();
        $this->assertSame('requested', $r->status);
        $this->assertEquals(100, $r->refund_amount, 'one of two at 100 each');
        $this->assertSame('original', $r->refund_destination, 'online order: back to the original payment');
        Storage::disk('public')->assertExists($r->photos[0]);
    }

    public function test_no_return_after_the_window_before_completion_or_twice(): void
    {
        $s = $this->completed();
        $service = app(MarketplaceReturnService::class);
        $this->ask($s);
        $this->assertSame('A return is already open for this order.', $service->cannotRequest($s['order'], $s['customer']->id));

        $late = $this->completed();
        $this->travel(4)->days();
        $this->assertSame('The 3-day return window has closed.', $service->cannotRequest($late['order']->fresh(), $late['customer']->id));

        $open = $this->makeMarketplaceOrderScenario('ready')['order'];
        $this->assertNotNull($service->cannotRequest($open, $open->customer_id));
    }

    // ── approval model ──────────────────────────────────────────────────

    public function test_seller_earnings_stay_held_while_a_return_is_open_and_the_refund_comes_out_of_them(): void
    {
        $s = $this->completed('wallet');
        $settlement = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->sole();
        $shareBefore = (float) $settlement->seller_share;
        $r = $this->ask($s);

        $this->travel(4)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame('held', $settlement->fresh()->status, 'an open return blocks the payout');

        $admin = $this->makeSuperAdmin();
        $service = app(MarketplaceReturnService::class);
        $this->assertTrue($service->decide($r, $admin, true, 'Photo shows the dent.')['ok']);
        Livewire::actingAs($s['owner']->user)->test(SellerOrders::class)->set('tab', 'returns')->call('receiveReturn', $r->id)->assertSet('flashType', 'success');
        $walletBefore = app(WalletService::class)->balance($s['customer']);
        $result = $service->requestRefund($r->fresh(), $admin, 'Damaged in packing');
        $this->assertTrue($result['ok'], $result['message']);

        $this->assertEquals($walletBefore + 100, app(WalletService::class)->balance($s['customer']), 'wallet order: refunded to the wallet');
        $this->assertSame('refunded', $r->fresh()->status);
        $this->assertEquals(round($shareBefore / 2, 2), (float) $settlement->fresh()->seller_share, 'half the goods returned: half the seller share');

        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame('released', $settlement->fresh()->status);
        $this->assertEquals(round($shareBefore / 2, 2), app(WalletService::class)->balance($s['owner']->user));
    }

    public function test_an_online_refund_goes_back_through_the_gateway_once(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->with('pay_RET1', 100.0, \Mockery::type('string'))->andReturn(['id' => 'rfnd_1']);
        $s = $this->completed('online');
        $r = $this->ask($s);
        $admin = $this->makeSuperAdmin();
        $service = app(MarketplaceReturnService::class);
        $service->decide($r, $admin, true, 'Accepted');
        $service->markReceived($r->fresh(), $admin);

        $this->assertTrue($service->requestRefund($r->fresh(), $admin, 'Damaged')['ok']);
        $this->assertFalse($service->requestRefund($r->fresh(), $admin, 'Again')['ok'], 'idempotent: nothing left to request');
        $this->assertSame('rfnd_1', $r->fresh()->refund_gateway_id);
        $this->assertEquals(100, Payment::where('gateway_payment_id', 'pay_RET1')->value('refunded_amount'));
    }

    public function test_limits_scope_and_maker_checker(): void
    {
        $s = $this->completed('wallet');
        $r = $this->ask($s);
        $service = app(MarketplaceReturnService::class);
        $admin = $this->makeSuperAdmin();
        $service->decide($r, $admin, true, 'ok');
        $service->markReceived($r->fresh(), $admin);

        $maker = $this->franchiseRefunder($s);
        $checker = $this->franchiseRefunder($s);
        $other = $this->completed('wallet');
        $outsider = $this->franchiseRefunder($other);

        // No franchise limit set: the franchise level cannot approve, so the request waits for someone who can.
        $this->assertTrue($service->requestRefund($r->fresh(), $maker, 'Dented')['ok']);
        $this->assertSame('awaiting_approval', $r->fresh()->refund_status);
        $this->assertFalse($service->approveRefund($r->fresh(), $checker, 'ok')['ok'], 'no franchise limit configured');

        Setting::set(MarketplaceReturnService::FRANCHISE_LIMIT_KEY, '500');
        $this->assertFalse($service->approveRefund($r->fresh(), $maker, 'self')['ok'], 'never your own request');
        $this->assertFalse($service->approveRefund($r->fresh(), $outsider, 'ok')['ok'], 'another franchise');
        $this->assertTrue($service->approveRefund($r->fresh(), $checker, 'Checked the photo')['ok']);
        $this->assertSame('refunded', $r->fresh()->status);
    }

    public function test_the_queue_needs_the_permission_and_escalation_runs_only_when_configured(): void
    {
        $s = $this->completed('wallet');
        $r = $this->ask($s);
        $this->actingAs($this->makeCustomer())->get(route('admin.shop-returns.index'))->assertForbidden();
        Livewire::actingAs($this->makeSuperAdmin())->test(ReturnsQueue::class)->assertSee('#'.$r->id);

        $admin = $this->makeSuperAdmin();
        app(MarketplaceReturnService::class)->decide($r, $admin, true, 'ok');
        app(MarketplaceReturnService::class)->markReceived($r->fresh(), $admin);
        $this->travel(10)->hours();
        $this->artisan('refunds:escalate-shop-returns')->assertSuccessful();
        $this->assertSame(0, $r->fresh()->escalation_level, 'off while unset');

        Setting::set(MarketplaceReturnService::FRANCHISE_LIMIT_KEY, '500'); // the franchise handles it first
        Setting::set(MarketplaceReturnService::ESCALATE_HOURS_KEY, '4');
        $this->artisan('refunds:escalate-shop-returns')->assertSuccessful();
        $this->assertGreaterThan(0, $r->fresh()->escalation_level);
    }

    public function test_a_seller_can_only_receive_their_own_shops_returns(): void
    {
        $s = $this->completed('wallet');
        $r = $this->ask($s);
        app(MarketplaceReturnService::class)->decide($r, $this->makeSuperAdmin(), true, 'ok');
        $other = $this->completed('wallet');

        Livewire::actingAs($other['owner']->user)->test(SellerOrders::class)->call('receiveReturn', $r->id)->assertNotFound();
        $this->assertSame('accepted', $r->fresh()->status);
    }
}
