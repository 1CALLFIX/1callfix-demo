<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\Rider\Earnings;
use App\Livewire\Rider\ShopDeliveries;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSettlement;
use App\Models\ParcelOrder;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * E-commerce Phase 3 (shop deliveries on the rider web, 2026-10-29): parcel riders take shop deliveries too (plan
 * D4), one job at a time across parcel and shop, the delivery finishes only with the customer's code, and cash from
 * a shop delivery is owed back like parcel cash.
 */
class RiderShopDeliveryTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    /** A ready delivery order (store at 1,1), offered to this parcel rider. */
    private function readyOrder(string $payment = 'online'): array
    {
        $s = $this->makeMarketplaceOrderScenario('ready', [
            'order_type' => 'delivery', 'subtotal' => 200, 'delivery_fee' => 40, 'total_amount' => 240,
            'payment_method' => $payment, 'payment_status' => $payment === 'cash' ? 'pending' : 'paid',
            'delivery_address_id' => null,
        ]);
        $s['store']->update(['lat' => 1.0, 'lng' => 1.0]);
        $s['order']->update(['delivery_address_id' => $this->makeAddress($s['customer'], $s['franchise'], $s['zone'])->id]);
        $s['rider'] = $this->makeParcelRiderIn($s['franchise'], $s['zone']);

        return $s;
    }

    private function offer(MarketplaceOrder $order, FieldWorker $rider): void
    {
        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $order->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $rider->id,
            'status' => 'notified', 'notified_at' => now(), 'distance_km' => 1.2,
        ]);
    }

    public function test_a_parcel_rider_is_a_candidate_for_shop_deliveries_unless_busy_or_stale(): void
    {
        $s = $this->readyOrder();
        $dispatch = app(DispatchService::class);
        $this->assertTrue($dispatch->findMarketplaceDeliveryRiderCandidates($s['order']->fresh())->pluck('provider.id')->contains($s['rider']->id));

        $s['rider']->update(['location_updated_at' => now()->subDay()]);
        $this->assertFalse($dispatch->findMarketplaceDeliveryRiderCandidates($s['order']->fresh())->pluck('provider.id')->contains($s['rider']->id), 'closed the rider web: stale location');

        $s['rider']->update(['location_updated_at' => now()]);
        $parcel = $this->makeParcelOrderScenario('assigned')['order'];
        $parcel->update(['assigned_worker_id' => $s['rider']->id]);
        $this->assertFalse($dispatch->findMarketplaceDeliveryRiderCandidates($s['order']->fresh())->pluck('provider.id')->contains($s['rider']->id), 'carrying a parcel');
    }

    public function test_a_rider_carrying_a_shop_delivery_is_not_offered_a_parcel(): void
    {
        $p = $this->makeParcelOrderScenario('pending');
        $s = $this->readyOrder();
        $s['order']->update(['assigned_worker_id' => $p['rider']->id, 'delivery_otp' => '4321']);

        $this->assertFalse(app(DispatchService::class)->findWorkerCandidates($p['order']->fresh())->pluck('provider.id')->contains($p['rider']->id));
    }

    public function test_the_rider_accepts_on_the_rider_web_and_delivers_only_with_the_customers_code(): void
    {
        $s = $this->readyOrder();
        $this->offer($s['order'], $s['rider']);
        $page = Livewire::actingAs($s['rider']->user)->test(ShopDeliveries::class);
        $page->assertViewHas('offers', fn ($o) => $o->count() === 1)->assertDispatched('provider-alert-offers');

        $page->call('accept', $s['order']->id)->assertSet('error', '');
        $order = $s['order']->fresh();
        $this->assertSame($s['rider']->id, $order->assigned_worker_id);
        $this->assertNotEmpty($order->delivery_otp);

        $page->set('otp', '0000'.($order->delivery_otp === '0000' ? '1' : ''))->call('deliver')
            ->assertSet('error', 'That code is not right. Ask the customer to read it from their order page.');
        $this->assertSame('ready', $order->fresh()->status);

        $page->set('otp', $order->delivery_otp)->call('deliver')->assertSet('notice', 'Delivered. Thank you!');
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_cash_from_a_shop_delivery_shows_as_owed_on_the_rider_earnings(): void
    {
        $s = $this->readyOrder('cash');
        $this->offer($s['order'], $s['rider']);
        $page = Livewire::actingAs($s['rider']->user)->test(ShopDeliveries::class)->call('accept', $s['order']->id);
        $page->assertSee('Collect ₹240.00 in cash', false);
        $page->set('otp', $s['order']->fresh()->delivery_otp)->call('deliver');

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->sole();
        $this->assertGreaterThan(0, (float) $row->cash_owed);
        Livewire::actingAs($s['rider']->user)->test(Earnings::class)
            ->assertViewHas('owed', fn ($owed) => round($owed, 2) === round((float) $row->cash_owed, 2))
            ->assertSee($s['order']->code);
    }

    public function test_one_job_at_a_time_and_another_riders_order_cannot_be_delivered(): void
    {
        $s = $this->readyOrder();
        $parcel = $this->makeParcelOrderScenario('assigned')['order'];
        $parcel->update(['assigned_worker_id' => $s['rider']->id]);
        $this->offer($s['order'], $s['rider']);
        Livewire::actingAs($s['rider']->user)->test(ShopDeliveries::class)->call('accept', $s['order']->id)
            ->assertSet('error', 'Finish your current job first.');
        $this->assertNull($s['order']->fresh()->assigned_worker_id);

        $other = $this->makeParcelRiderIn($s['franchise'], $s['zone']);
        $s['order']->update(['assigned_worker_id' => $other->id, 'delivery_otp' => '5555']);
        Livewire::actingAs($s['rider']->user)->test(ShopDeliveries::class)->set('otp', '5555')->call('deliver');
        $this->assertSame('ready', $s['order']->fresh()->status, 'not their job');
        $this->assertTrue(ParcelOrder::whereKey($parcel->id)->exists());
    }
}
