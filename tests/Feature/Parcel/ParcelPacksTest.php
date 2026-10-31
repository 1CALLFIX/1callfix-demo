<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Actions\CustomerCancelParcelOrderAction;
use App\Contracts\PaymentGateway;
use App\Jobs\ParcelDispatchJob;
use App\Livewire\Customer\Parcel\Create;
use App\Livewire\Customer\Parcel\Packs;
use App\Livewire\ParcelPacks\Manage as PacksAdmin;
use App\Models\Commission;
use App\Models\FieldWorkerCashDue;
use App\Models\ParcelOrder;
use App\Models\ParcelPack;
use App\Models\ParcelPackPurchase;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\CommissionService;
use App\Services\Parcel\ParcelPackService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel Stage 2 — prepaid parcel packs for businesses (owner decision 2026-10-31). Super Admin creates packs; bought
 * online or from the wallet only; one credit per parcel covers the base fare + distance up to the pack's km; the
 * rest is paid online; THUMB RULE: a parcel using a pack credit is never paid in cash; a cancelled parcel gives the
 * credit back.
 */
class ParcelPacksTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::set('payment.wallet_enabled', '1');
    }

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);
        $pack = ParcelPack::create(['name' => 'Shop 10', 'parcel_count' => 10, 'max_km' => 5, 'price' => 300, 'validity_days' => 30]);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider', 'pack');
    }

    private function ownedPack(array $w): ParcelPackPurchase
    {
        app(WalletService::class)->credit($w['customer'], 1000, reason: 'test', ref: 'admin-adjust:'.\Illuminate\Support\Str::uuid());
        $result = app(ParcelPackService::class)->buy($w['customer'], $w['pack'], 'wallet');

        return ParcelPackPurchase::findOrFail($result['purchase_id']);
    }

    private function create(array $w, array $extra = []): ParcelOrder
    {
        return app(CreateParcelOrderAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'online', 'restricted_items_confirmed' => true,
        ], $extra));
    }

    private function mockGateway(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        $gateway->shouldReceive('createRawOrder')->andReturnUsing(fn ($amount) => ['razorpay_order_id' => 'order_'.\Illuminate\Support\Str::random(6), 'key_id' => 'rzp_test', 'amount' => (int) round($amount * 100), 'currency' => 'INR']);
    }

    // ═══════════════════════ admin ═══════════════════════

    public function test_only_super_admin_manages_packs_and_changes_are_logged(): void
    {
        $this->actingAs($this->makeCustomer())->get(route('admin.parcel-packs.index'))->assertForbidden();

        Livewire::actingAs($this->makeSuperAdmin())->test(PacksAdmin::class)
            ->set('name', 'Office 50')->set('parcelCount', '50')->set('maxKm', '5')->set('price', '1500')->set('validityDays', '30')
            ->call('save')->assertHasNoErrors()->assertSee('Office 50');

        $pack = ParcelPack::sole();
        $this->assertSame(50, $pack->parcel_count);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $pack->id]);
    }

    // ═══════════════════════ buying ═══════════════════════

    public function test_buying_from_the_wallet_starts_the_pack_at_once(): void
    {
        $w = $this->world();
        $purchase = $this->ownedPack($w);

        $this->assertSame('active', $purchase->status);
        $this->assertSame(10, $purchase->remaining());
        $this->assertTrue($purchase->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertEquals(700, app(WalletService::class)->balance($w['customer']));
        $this->assertSame('parcel_pack', Payment::where('parcel_pack_purchase_id', $purchase->id)->sole()->purpose);
    }

    public function test_an_online_pack_starts_only_on_the_capture_and_a_duplicate_capture_is_refunded(): void
    {
        $this->mockGateway();
        $w = $this->world();
        $result = app(ParcelPackService::class)->buy($w['customer'], $w['pack'], 'online');
        $purchase = ParcelPackPurchase::findOrFail($result['purchase_id']);
        $this->assertTrue($result['requires_payment']);
        $this->assertSame('pending_payment', $purchase->status);

        $payment = Payment::where('parcel_pack_purchase_id', $purchase->id)->sole();
        $payment->update(['status' => 'captured', 'gateway_payment_id' => 'pay_PACK1']);
        app(ParcelPackService::class)->markPaid($payment);
        $this->assertSame('active', $purchase->fresh()->status);

        $this->mock(PaymentGateway::class)->shouldReceive('refund')->once()->with('pay_PACK2', 300.0, \Mockery::type('string'));
        $second = Payment::create(['parcel_pack_purchase_id' => $purchase->id, 'purpose' => 'parcel_pack', 'amount' => 300, 'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_PACK2', 'status' => 'captured']);
        app(ParcelPackService::class)->markPaid($second);
        $this->assertSame('refunded', $second->fresh()->status);
    }

    public function test_packs_cannot_be_bought_with_cash(): void
    {
        $w = $this->world();

        $this->expectExceptionMessage('Parcel packs are paid online or from your wallet.');
        app(ParcelPackService::class)->buy($w['customer'], $w['pack'], 'cash');
    }

    // ═══════════════════════ using ═══════════════════════

    public function test_a_parcel_inside_the_pack_km_is_fully_covered_and_dispatched_at_once(): void
    {
        $w = $this->world();
        $purchase = $this->ownedPack($w);

        $order = $this->create($w, ['parcel_pack_purchase_id' => $purchase->id]);

        $this->assertSame('pack', $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals((float) $order->price_quoted, (float) $order->pack_covered);
        $this->assertEquals(0, $order->amountDue());
        $this->assertSame(9, $purchase->fresh()->remaining());
        Queue::assertPushed(ParcelDispatchJob::class);
    }

    public function test_extras_beyond_the_pack_are_paid_online(): void
    {
        $this->mockGateway();
        $w = $this->world();
        Setting::set('parcel.per_kg_rate', '10');
        $purchase = $this->ownedPack($w);

        $order = $this->create($w, ['parcel_pack_purchase_id' => $purchase->id, 'package_weight_kg' => 5]);

        $this->assertSame('online', $order->payment_method);
        $this->assertTrue($order->isAwaitingOnlinePayment());
        $this->assertEquals(50, $order->amountDue(), '5 kg × 10 is outside the pack');
        $checkout = app(\App\Services\Parcel\ParcelPaymentService::class)->startOnlinePayment($order);
        $this->assertEquals(50, Payment::find($checkout['payment_id'])->amount);
    }

    public function test_cash_is_rejected_on_a_parcel_that_uses_a_pack_credit(): void
    {
        $w = $this->world();
        $purchase = $this->ownedPack($w);

        try {
            $this->create($w, ['parcel_pack_purchase_id' => $purchase->id, 'payment_method' => 'cash']);
            $this->fail('THUMB RULE: a pack credit is a benefit, cash must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not in cash', $e->getMessage());
        }
        $this->assertSame(10, $purchase->fresh()->remaining(), 'no credit taken');
        $this->assertSame(0, ParcelOrder::count());
    }

    public function test_a_pack_with_no_credits_another_customers_or_an_expired_one_is_refused(): void
    {
        $w = $this->world();
        $purchase = $this->ownedPack($w);
        $purchase->update(['parcels_used' => 10]);

        try {
            $this->create($w, ['parcel_pack_purchase_id' => $purchase->id]);
            $this->fail('A used-up pack must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no credits left', $e->getMessage());
        }

        $purchase->update(['parcels_used' => 0, 'expires_at' => now()->subDay()]);
        $this->expectExceptionMessage('no credits left');
        $this->create($w, ['parcel_pack_purchase_id' => $purchase->id]);
    }

    public function test_a_cancelled_parcel_gives_its_credit_back_once(): void
    {
        $w = $this->world();
        $purchase = $this->ownedPack($w);
        $order = $this->create($w, ['parcel_pack_purchase_id' => $purchase->id]);
        $this->assertSame(9, $purchase->fresh()->remaining());

        app(CustomerCancelParcelOrderAction::class)->execute($order, $w['customer'], 'Changed my mind');
        $this->assertSame(10, $purchase->fresh()->remaining());

        $order->fresh()->update(['status' => 'cancelled', 'cancellation_note' => 'again']);
        $this->assertSame(10, $purchase->fresh()->remaining(), 'never twice');
    }

    public function test_the_rider_is_paid_their_normal_share_on_a_pack_parcel(): void
    {
        $w = $this->world();
        $order = $this->create($w, ['parcel_pack_purchase_id' => $this->ownedPack($w)->id]);
        $order->update(['status' => 'delivered', 'assigned_worker_id' => $w['rider']->id, 'delivered_at' => now(), 'price_final' => $order->price_quoted]);

        $commission = app(CommissionService::class)->applyForParcelOrder($order->fresh());

        $this->assertGreaterThan(0, (float) $commission->provider_commission);
        $this->assertEquals((float) $commission->provider_commission, app(WalletService::class)->balance($w['rider']->user));
        $this->assertSame(0, FieldWorkerCashDue::count(), 'not a cash order');
    }

    // ═══════════════════════ web ═══════════════════════

    public function test_the_customer_buys_on_the_packs_page_and_uses_it_with_cash_hidden(): void
    {
        $w = $this->world();
        app(WalletService::class)->credit($w['customer'], 500, reason: 'test', ref: 'admin-adjust:'.\Illuminate\Support\Str::uuid());
        Setting::set('payment.cash_enabled', '1');

        Livewire::actingAs($w['customer'])->test(Packs::class)
            ->assertSee('Shop 10')
            ->call('buy', $w['pack']->id, 'wallet')
            ->assertSet('notice', 'Done. 10 parcels are ready to use.');

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('pickupMode', 'saved')->set('pickupAddressId', $w['pickup']->id)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id)
            ->set('recipientPhone', '9876543210')
            ->assertSee('Use my parcel pack')
            ->assertViewHas('methods', fn ($m) => ! array_key_exists('cash', $m))
            ->set('restrictedConfirmed', true)
            ->call('submit')->assertHasNoErrors();

        $this->assertSame('pack', ParcelOrder::sole()->payment_method);
    }

    // ═══════════════════════ API ═══════════════════════

    public function test_the_api_lists_buys_and_uses_a_pack_and_rejects_cash(): void
    {
        $w = $this->world();
        app(WalletService::class)->credit($w['customer'], 500, reason: 'test', ref: 'admin-adjust:'.\Illuminate\Support\Str::uuid());
        Setting::set('payment.cash_enabled', '1');

        $this->actingAs($w['customer'], 'sanctum')->getJson('/api/parcel-packs?zone_id='.$w['zone']->id)
            ->assertOk()->assertJsonPath('data.on_sale.0.name', 'Shop 10');
        $this->actingAs($w['customer'], 'sanctum')->postJson("/api/parcel-packs/{$w['pack']->id}/buy", ['method' => 'wallet'])
            ->assertCreated()->assertJsonPath('data.requires_payment', false);

        $payload = ['pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id, 'recipient_phone' => '9876543210', 'restricted_items_confirmed' => true, 'use_parcel_pack' => true];
        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', $payload + ['payment_method' => 'cash'])->assertStatus(409);
        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', $payload + ['payment_method' => 'wallet'])
            ->assertCreated()->assertJsonPath('data.payment_method', 'pack')->assertJsonPath('data.amount_due', 0);
    }

    public function test_the_api_books_a_protected_parcel(): void
    {
        $w = $this->world();
        Setting::set('parcel.protection_fee_percent', '2');
        Setting::set('parcel.protection_max_cover', '10000');
        Setting::set('payment.cash_enabled', '1');

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id, 'recipient_phone' => '9876543210',
            'restricted_items_confirmed' => true, 'payment_method' => 'cash', 'declared_value' => 2000,
        ])->assertCreated()->assertJsonPath('data.declared_value', 2000)->assertJsonPath('data.protection_fee', 40);
    }
}
