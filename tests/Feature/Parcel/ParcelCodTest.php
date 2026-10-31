<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Models\Address;
use App\Models\FieldWorkerCashDue;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\Parcel\FieldWorkerCashDueService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel Stage 2 — cash on delivery for shops through the HQ ledger: the rider collects the item price at the drop,
 * owes it to HQ in their cash dues, and the sender's wallet is credited once that due is fully settled — once.
 */
class ParcelCodTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider');
    }

    private function create(array $w, array $extra = []): ParcelOrder
    {
        return app(CreateParcelOrderAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'restricted_items_confirmed' => true,
        ], $extra));
    }

    private function deliver(array $w, ParcelOrder $order): ParcelOrder
    {
        $order->update(['status' => 'picked_up', 'assigned_worker_id' => $w['rider']->id, 'picked_up_at' => now(), 'payment_status' => 'paid', 'delivery_otp' => '2222']);

        return app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], '2222');
    }

    private function topUp($user, float $amount): void
    {
        app(WalletService::class)->credit($user, $amount, reason: 'test', ref: 'admin-adjust:'.Str::uuid());
    }

    public function test_cash_on_delivery_is_refused_while_unset_above_the_max_and_with_more_drops(): void
    {
        $w = $this->world();
        try {
            $this->create($w, ['cod_amount' => 500]);
            $this->fail('COD must be refused while its setting is unset.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cash on delivery is not available here.', $e->getMessage());
        }

        Setting::set('parcel.cod_max_amount', '2000');
        try {
            $this->create($w, ['cod_amount' => 2500]);
            $this->fail('COD above the max must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('between 1 and 2000', $e->getMessage());
        }

        Setting::set('parcel.multi_drop_max_stops', '3');
        Setting::set('parcel.extra_stop_fee', '10');
        $second = Address::create(['user_id' => $w['customer']->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'label' => 'B', 'lat' => 1.02, 'lng' => 1.02, 'address_line' => 'B']);
        $this->expectExceptionMessage('Cash on delivery works with one drop point only.');
        $this->create($w, ['cod_amount' => 500, 'extra_stops' => [['address_id' => $second->id, 'recipient_phone' => '9123456780']]]);
    }

    public function test_the_sender_is_paid_only_when_the_rider_has_fully_settled_and_only_once(): void
    {
        $w = $this->world();
        Setting::set('parcel.cod_max_amount', '2000');
        Setting::set('payment.wallet_enabled', '1');
        $this->topUp($w['customer'], 1000);
        $order = $this->deliver($w, $this->create($w, ['cod_amount' => 800, 'payment_method' => 'wallet']));

        $this->assertNotNull($order->cod_collected_at);
        $due = FieldWorkerCashDue::where('parcel_order_id', $order->id)->sole();
        $this->assertEquals(800, (float) $due->cod_portion);
        $this->assertEquals(0, (float) $due->platform_portion, 'a wallet-paid delivery: only the item price is owed');
        $this->assertSame('outstanding', $due->status, 'the rider\'s earnings alone do not cover 800');
        $senderBefore = app(WalletService::class)->balance($w['customer']);

        $this->topUp($w['rider']->user, 1000);
        app(FieldWorkerCashDueService::class)->settle($w['rider']);

        $this->assertSame('settled', $due->fresh()->status);
        $this->assertNotNull($due->fresh()->cod_remitted_at);
        $this->assertEquals($senderBefore + 800, app(WalletService::class)->balance($w['customer']));

        app(FieldWorkerCashDueService::class)->settle($w['rider']);
        $this->assertEquals($senderBefore + 800, app(WalletService::class)->balance($w['customer']), 'never twice');
    }

    public function test_on_a_cash_order_the_rider_owes_the_commission_and_the_item_price(): void
    {
        $w = $this->world();
        Setting::set('parcel.cod_max_amount', '2000');
        $order = $this->deliver($w, $this->create($w, ['cod_amount' => 500]));

        $due = FieldWorkerCashDue::where('parcel_order_id', $order->id)->sole();
        $this->assertEquals(500, (float) $due->cod_portion);
        $this->assertEqualsWithDelta((float) $due->platform_portion + (float) $due->franchise_portion + 500, (float) $due->amount_owed, 0.01);
    }

    public function test_the_rider_page_asks_to_collect_the_item_price(): void
    {
        $w = $this->world();
        Setting::set('parcel.cod_max_amount', '2000');
        $order = $this->create($w, ['cod_amount' => 750]);
        $order->update(['status' => 'picked_up', 'assigned_worker_id' => $w['rider']->id, 'picked_up_at' => now(), 'payment_status' => 'paid', 'delivery_otp' => '2222']);

        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->assertSee('item price (cash on delivery)');
    }

    public function test_the_api_books_cash_on_delivery(): void
    {
        $w = $this->world();
        Setting::set('parcel.cod_max_amount', '2000');
        Setting::set('payment.cash_enabled', '1');

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id, 'recipient_phone' => '9876543210',
            'restricted_items_confirmed' => true, 'payment_method' => 'cash', 'cod_amount' => 650,
        ])->assertCreated()->assertJsonPath('data.cod_amount', 650);
    }
}
