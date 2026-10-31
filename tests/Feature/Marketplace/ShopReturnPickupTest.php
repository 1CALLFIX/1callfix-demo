<?php

namespace Tests\Feature\Marketplace;

use App\Actions\CompleteMarketplaceOrderAction;
use App\Livewire\Customer\Shop\OrderShow;
use App\Livewire\Rider\ShopDeliveries;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\MarketplaceReturn;
use App\Models\MarketplaceSettlement;
use App\Models\Setting;
use App\Services\DispatchService;
use App\Services\Marketplace\MarketplaceReturnPickupService;
use App\Services\Marketplace\MarketplaceReturnService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Shop go-live (owner 2026-10-31): a rider collects an accepted return from the customer and takes it back to the
 * shop. Owner decision: the seller pays the rider's fee out of their held earnings; the customer pays nothing.
 * Blank fee setting = no rider pickup (marked received by hand, as before).
 */
class ShopReturnPickupTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** A completed online-paid (wallet) delivery order, delivered by a rider, with an accepted return. */
    private function acceptedReturn(?string $fee = '40', string $orderType = 'delivery'): array
    {
        if ($fee !== null) {
            Setting::set(MarketplaceReturnPickupService::FEE_KEY, $fee);
        }
        $s = $this->makeMarketplaceOrderScenario('ready', [
            'order_type' => $orderType, 'subtotal' => 200, 'delivery_fee' => $orderType === 'delivery' ? 30 : 0,
            'total_amount' => $orderType === 'delivery' ? 230 : 200, 'payment_method' => 'wallet', 'payment_status' => 'paid',
            'delivery_address_id' => null,
        ]);
        $s['store']->update(['lat' => 1.0, 'lng' => 1.0]);
        $s['rider'] = $this->makeParcelRiderIn($s['franchise'], $s['zone']);
        $s['order']->update([
            'delivery_address_id' => $this->makeAddress($s['customer'], $s['franchise'], $s['zone'])->id,
            'assigned_worker_id' => $orderType === 'delivery' ? $s['rider']->id : null,
            'delivery_otp' => $orderType === 'delivery' ? '1234' : null,
        ]);
        $s['order']->items()->create(['product_id' => $s['product']->id, 'product_name_snapshot' => 'Bottle', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        app(CompleteMarketplaceOrderAction::class)->execute($s['order']->id, ...($orderType === 'delivery' ? [$s['rider'], '1234'] : []));
        $s['order'] = $s['order']->fresh('items');

        $item = $s['order']->items->first();
        $returns = app(MarketplaceReturnService::class);
        $r = $returns->request($s['order'], $s['customer'], 'damaged', 'Dented.', [$item->id => 1], ['returns/x.jpg']);
        $s['admin'] = $this->makeSuperAdmin();
        $this->assertTrue($returns->decide($r, $s['admin'], true, 'Photo shows the dent.')['ok']);
        $s['return'] = $r->fresh();

        return $s;
    }

    public function test_an_accepted_delivery_return_opens_a_rider_pickup_at_the_set_fee(): void
    {
        $s = $this->acceptedReturn('40');
        $this->assertSame('open', $s['return']->pickup_status);
        $this->assertEquals(40, $s['return']->pickup_fee);
    }

    public function test_no_fee_set_or_a_pickup_order_means_no_rider_pickup(): void
    {
        $this->assertNull($this->acceptedReturn(null)['return']->pickup_status, 'blank setting = received by hand');
        $this->assertNull($this->acceptedReturn('40', 'pickup')['return']->pickup_status, 'the customer collected it, so they bring it back');
    }

    public function test_full_walk_customer_code_then_shop_code_pays_the_rider_from_the_seller_share(): void
    {
        $s = $this->acceptedReturn('40');
        $settlement = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->sole();
        $shareBefore = (float) $settlement->seller_share;
        $walletBefore = app(WalletService::class)->balance($s['rider']->user);
        $customerBefore = app(WalletService::class)->balance($s['customer']);

        $page = Livewire::actingAs($s['rider']->user)->test(ShopDeliveries::class)
            ->assertViewHas('pickups', fn ($p) => $p->count() === 1)
            ->call('acceptPickup', $s['return']->id)->assertSet('error', '');
        $r = $s['return']->fresh();
        $this->assertSame('assigned', $r->pickup_status);
        $this->assertSame($s['rider']->id, $r->pickup_worker_id);

        // The customer sees the pickup code on the order page.
        Livewire::actingAs($s['customer'])->test(OrderShow::class, ['order' => $s['order']])->assertSee($r->pickup_otp);
        $this->actingAs($s['rider']->user);

        $page->set('otp', '0000')->call('collectReturn');
        $this->assertSame('assigned', $r->fresh()->pickup_status, 'wrong code');
        $page->set('otp', $r->pickup_otp)->call('collectReturn')->assertSet('error', '');
        $this->assertSame('picked', $r->fresh()->pickup_status);

        // The seller sees the drop code; they cannot mark it received by hand while the rider has it.
        Livewire::actingAs($s['owner']->user)->test(SellerOrders::class)->set('tab', 'returns')->assertSee($r->drop_otp)
            ->call('receiveReturn', $r->id);
        $this->assertSame('accepted', $r->fresh()->status);
        $this->actingAs($s['rider']->user);

        $page->set('otp', $r->drop_otp)->call('dropReturn')->assertSet('error', '');
        $r = $r->fresh();
        $this->assertSame('dropped', $r->pickup_status);
        $this->assertSame('received', $r->status);
        $this->assertSame(MarketplaceReturn::REFUND_AWAITING_REQUEST, $r->refund_status, 'the refund now goes through the approval model');
        $this->assertEquals(40, $r->pickup_fee_seller);
        $this->assertEquals(0, $r->pickup_fee_platform);
        $this->assertEqualsWithDelta($shareBefore - 40, (float) $settlement->fresh()->seller_share, 0.001, 'the seller pays the fee');
        $this->assertEqualsWithDelta($walletBefore + 40, app(WalletService::class)->balance($s['rider']->user), 0.001);
        $this->assertEqualsWithDelta($customerBefore, app(WalletService::class)->balance($s['customer']), 0.001, 'the customer pays nothing');
    }

    public function test_the_fee_beyond_the_seller_share_is_carried_by_the_platform(): void
    {
        $s = $this->acceptedReturn('40');
        MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->update(['seller_share' => 15]);
        $pickups = app(MarketplaceReturnPickupService::class);
        $r = $pickups->accept($s['return']->id, $s['rider']);
        $pickups->confirmPickup($s['rider'], $r->pickup_otp);
        $r = $pickups->confirmDrop($s['rider'], $r->drop_otp);

        $this->assertEquals(15, $r->pickup_fee_seller);
        $this->assertEquals(25, $r->pickup_fee_platform);
        $this->assertEquals(0, (float) MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->value('seller_share'));
    }

    public function test_only_one_rider_wins_and_a_busy_rider_is_not_offered_other_work(): void
    {
        $s = $this->acceptedReturn('40');
        $other = $this->makeParcelRiderIn($s['franchise'], $s['zone']);
        $pickups = app(MarketplaceReturnPickupService::class);
        $pickups->accept($s['return']->id, $s['rider']);

        $this->assertCount(0, $pickups->openPickupsFor($other));
        $this->assertTrue($pickups->isBusy($s['rider']));
        $parcel = $this->makeParcelOrderScenario('pending')['order'];
        $parcel->update(['franchise_id' => $s['franchise']->id, 'zone_id' => $s['zone']->id]);
        $this->assertFalse(app(DispatchService::class)->findWorkerCandidates($parcel->fresh())->pluck('provider.id')->contains($s['rider']->id), 'collecting a return = busy');

        $this->expectExceptionMessage('This pickup has already been taken.');
        $pickups->accept($s['return']->id, $other);
    }

    public function test_the_seller_can_still_receive_by_hand_while_no_rider_has_taken_it(): void
    {
        $s = $this->acceptedReturn('40');
        Livewire::actingAs($s['owner']->user)->test(SellerOrders::class)->set('tab', 'returns')->call('receiveReturn', $s['return']->id);
        $r = $s['return']->fresh();
        $this->assertSame('received', $r->status);
        $this->assertSame('cancelled', $r->pickup_status, 'the open pickup is withdrawn; no fee');
        $this->assertCount(0, app(MarketplaceReturnPickupService::class)->openPickupsFor($s['rider']));
    }

    public function test_seller_earnings_stay_held_until_the_return_is_settled(): void
    {
        $s = $this->acceptedReturn('40');
        $this->travel(5)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame('held', MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->value('status'));
    }
}
