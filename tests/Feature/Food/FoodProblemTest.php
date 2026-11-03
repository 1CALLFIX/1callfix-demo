<?php

namespace Tests\Feature\Food;

use App\Actions\CompleteMarketplaceOrderAction;
use App\Livewire\Customer\Shop\OrderShow;
use App\Livewire\MarketplaceReturns\Index as ReturnsQueue;
use App\Models\MarketplaceReturn;
use App\Models\MarketplaceSettlement;
use App\Models\Setting;
use App\Services\Marketplace\MarketplaceReturnService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Food F4 (2026-11-03) — problems with a food order. Proven here: the customer reports missing / wrong / spilled /
 * poor-quality food within food.issue_window_minutes (photo per Food Settings); nothing comes back, so accepting
 * opens the refund straight away; the admin must pick whose fault it was; the refund goes through the approval model
 * (maker-checker kept) and is split by Food Settings — restaurant's fault out of its held earnings (capped at them),
 * damaged on the way / not clear paid by the platform with a strike for the rider, never a rider deduction; the
 * restaurant is paid only after the problem window, never while a problem is open.
 */
class FoodProblemTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** A delivered, wallet-paid food delivery order (2 x ₹100 + ₹30 delivery). */
    private function deliveredFoodOrder(string $orderType = 'delivery'): array
    {
        $s = $this->makeMarketplaceOrderScenario('ready', [
            'module' => Modules::FOOD, 'order_type' => $orderType, 'subtotal' => 200,
            'delivery_fee' => $orderType === 'delivery' ? 30 : 0, 'total_amount' => $orderType === 'delivery' ? 230 : 200,
            'payment_method' => 'wallet', 'payment_status' => 'paid', 'delivery_address_id' => null,
        ]);
        $s['store']->update(['module' => Modules::FOOD, 'lat' => 1.0, 'lng' => 1.0]);
        $s['rider'] = $this->makeParcelRiderIn($s['franchise'], $s['zone']);
        $s['order']->update([
            'delivery_address_id' => $this->makeAddress($s['customer'], $s['franchise'], $s['zone'])->id,
            'assigned_worker_id' => $orderType === 'delivery' ? $s['rider']->id : null,
            'delivery_otp' => $orderType === 'delivery' ? '1234' : null,
        ]);
        $s['order']->items()->create(['product_id' => $s['product']->id, 'product_name_snapshot' => 'Veg Meals', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        app(CompleteMarketplaceOrderAction::class)->execute($s['order']->id, ...($orderType === 'delivery' ? [$s['rider'], '1234'] : []));
        $s['order'] = $s['order']->fresh('items');
        $s['admin'] = $this->makeSuperAdmin();

        return $s;
    }

    private function report(array $s, string $reason = 'missing', int $qty = 1): MarketplaceReturn
    {
        return app(MarketplaceReturnService::class)->request($s['order'], $s['customer'], $reason, 'One meal was not in the bag.', [$s['order']->items->first()->id => $qty], ['returns/x.jpg']);
    }

    private function settlement(array $s): MarketplaceSettlement
    {
        return MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->sole();
    }

    public function test_the_restaurant_is_paid_only_after_the_problem_window(): void
    {
        Setting::set('food.issue_window_minutes', '45');
        $s = $this->deliveredFoodOrder();

        $row = $this->settlement($s);
        $this->assertEqualsWithDelta(45, $s['order']->completed_at->diffInMinutes($row->release_at), 0.01, 'minutes, not the shop return days');
    }

    public function test_a_problem_can_be_reported_only_inside_the_window_and_once(): void
    {
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        $this->assertNull($returns->cannotRequest($s['order'], $s['customer']->id));

        $this->report($s);
        $this->assertSame('A problem is already reported for this order.', $returns->cannotRequest($s['order'], $s['customer']->id));

        MarketplaceReturn::query()->delete();
        $this->travel(121)->minutes();
        $this->assertSame('The time to report a problem with this order is over.', $returns->cannotRequest($s['order']->fresh(), $s['customer']->id));
    }

    public function test_shop_reasons_are_refused_and_the_photo_follows_the_food_setting(): void
    {
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        $item = $s['order']->items->first();

        try {
            $returns->request($s['order'], $s['customer'], 'damaged', 'It was bad.', [$item->id => 1], ['x.jpg']);
            $this->fail('a shop reason was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Pick what went wrong.', $e->getMessage());
        }
        try {
            $returns->request($s['order'], $s['customer'], 'missing', 'One meal missing.', [$item->id => 1], []);
            $this->fail('no photo was accepted while the photo switch is on');
        } catch (\RuntimeException $e) {
            $this->assertSame('Add at least one photo of what arrived.', $e->getMessage());
        }

        Setting::set('food.issue_photo_required', '0');
        $r = $returns->request($s['order'], $s['customer'], 'missing', 'One meal missing.', [$item->id => 1], []);
        $this->assertEquals(100, $r->refund_amount);
        $this->assertSame($s['rider']->id, $r->field_worker_id, 'the rider who delivered it is recorded');
    }

    public function test_the_customer_reports_from_the_order_page(): void
    {
        Setting::set('food.issue_photo_required', '0');
        $s = $this->deliveredFoodOrder();

        Livewire::actingAs($s['customer'])->test(OrderShow::class, ['order' => $s['order']])
            ->assertSee('Report a problem')
            ->assertSet('returnReason', 'missing')
            ->set('returnReason', 'spilled')
            ->set('returnDescription', 'The curry spilled all over the bag.')
            ->set('returnQty.'.$s['order']->items->first()->id, 2)
            ->call('requestReturn')
            ->assertSet('flash', 'Thanks for telling us. We will look at it and reply here.')
            ->assertSee('Problem reported. We are looking at it.');

        $r = MarketplaceReturn::sole();
        $this->assertSame('spilled', $r->reason);
        $this->assertEquals(200, $r->refund_amount);
    }

    public function test_accepting_needs_a_fault_and_opens_the_refund_straight_away(): void
    {
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        $r = $this->report($s);

        $this->assertSame('Pick whose fault it was.', $returns->decide($r, $s['admin'], true, 'Checked the photo.')['message']);
        $this->assertTrue($returns->decide($r, $s['admin'], true, 'Checked the photo.', null, 'restaurant')['ok']);

        $r = $r->fresh();
        $this->assertSame(MarketplaceReturn::RECEIVED, $r->status, 'nothing comes back');
        $this->assertSame(MarketplaceReturn::REFUND_AWAITING_REQUEST, $r->refund_status);
        $this->assertNull($r->pickup_status, 'no rider pickup for food');
        $this->assertSame('restaurant', $r->fault);
    }

    public function test_restaurant_fault_comes_out_of_the_restaurants_held_earnings(): void
    {
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        $before = $this->settlement($s);
        $customerBefore = app(WalletService::class)->balance($s['customer']);

        $r = $this->report($s);
        $returns->decide($r, $s['admin'], true, 'Missing item.', null, 'restaurant');
        $this->assertTrue($returns->requestRefund($r->fresh(), $s['admin'], 'Missing meal.')['ok']);

        $after = $this->settlement($s);
        $r = $r->fresh();
        $this->assertSame(MarketplaceReturn::REFUNDED, $r->status);
        $this->assertEqualsWithDelta($customerBefore + 100, app(WalletService::class)->balance($s['customer']), 0.001);
        $this->assertEqualsWithDelta((float) $before->seller_share - 100, (float) $after->seller_share, 0.001, 'restaurant pays 100% by default');
        $this->assertEqualsWithDelta((float) $before->goods_platform, (float) $after->goods_platform, 0.001);
        $this->assertEqualsWithDelta((float) $before->goods_franchise, (float) $after->goods_franchise, 0.001, 'the franchise is never cut');
        $this->assertEquals(100, $r->restaurant_paid);
        $this->assertEquals(0, $r->platform_paid);
    }

    public function test_the_restaurant_never_pays_more_than_its_held_earnings(): void
    {
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->update(['seller_share' => 60]);
        $platformBefore = (float) $this->settlement($s)->goods_platform;

        $r = $this->report($s);
        $returns->decide($r, $s['admin'], true, 'Missing.', null, 'restaurant');
        $returns->requestRefund($r->fresh(), $s['admin'], 'Missing meal.');

        $r = $r->fresh();
        $this->assertEquals(60, $r->restaurant_paid);
        $this->assertEquals(40, $r->platform_paid);
        $this->assertEquals(0, (float) $this->settlement($s)->seller_share);
        $this->assertEqualsWithDelta($platformBefore - 40, (float) $this->settlement($s)->goods_platform, 0.001);
    }

    public function test_damaged_on_the_way_is_paid_by_the_platform_and_is_a_strike_for_the_rider(): void
    {
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        $before = $this->settlement($s);
        $riderBefore = app(WalletService::class)->balance($s['rider']->user);

        $r = $this->report($s, 'spilled', 2);
        $returns->decide($r, $s['admin'], true, 'Bag was crushed.', null, 'transit');
        $returns->requestRefund($r->fresh(), $s['admin'], 'Spilled on the way.');

        $after = $this->settlement($s);
        $this->assertEqualsWithDelta((float) $before->seller_share, (float) $after->seller_share, 0.001, 'restaurant pays 0% by default');
        $this->assertEqualsWithDelta((float) $before->goods_platform - 200, (float) $after->goods_platform, 0.001);
        $this->assertEqualsWithDelta($riderBefore, app(WalletService::class)->balance($s['rider']->user), 0.001, 'never a rider deduction');
        $this->assertSame(1, MarketplaceReturn::riderStrikes($s['rider']->id));
        $this->assertEquals(200, $r->fresh()->platform_paid);
    }

    public function test_the_shares_are_super_admin_settings(): void
    {
        Setting::set('food.fault_unclear_percent', '50');
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);

        $r = $this->report($s);
        $returns->decide($r, $s['admin'], true, 'Cannot tell.', null, 'unclear');
        $returns->requestRefund($r->fresh(), $s['admin'], 'Unclear.');

        $r = $r->fresh();
        $this->assertEquals(50, $r->restaurant_paid);
        $this->assertEquals(50, $r->platform_paid);
        $this->assertSame(0, MarketplaceReturn::riderStrikes($s['rider']->id), 'not clear = no strike');
    }

    public function test_a_pickup_order_cannot_be_damaged_on_the_way(): void
    {
        $s = $this->deliveredFoodOrder('pickup');
        $r = $this->report($s);
        $result = app(MarketplaceReturnService::class)->decide($r, $s['admin'], true, 'Spilled.', null, 'transit');

        $this->assertFalse($result['ok']);
        $this->assertSame(MarketplaceReturn::REQUESTED, $r->fresh()->status);
    }

    public function test_the_restaurant_is_not_paid_while_a_problem_is_open(): void
    {
        $s = $this->deliveredFoodOrder();
        $this->report($s);
        $this->travel(3)->hours();

        app(\App\Services\Marketplace\MarketplaceSettlementService::class)->releaseDue();
        $this->assertSame('held', $this->settlement($s)->status);
    }

    public function test_maker_checker_still_applies_to_food_refunds(): void
    {
        Setting::set(MarketplaceReturnService::DUAL_APPROVAL_KEY, '50');
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        $r = $this->report($s);
        $returns->decide($r, $s['admin'], true, 'Missing.', null, 'restaurant');

        $returns->requestRefund($r->fresh(), $s['admin'], 'Missing meal.');
        $this->assertSame(MarketplaceReturn::REFUND_AWAITING_APPROVAL, $r->fresh()->refund_status);
        $this->assertFalse($returns->approveRefund($r->fresh(), $s['admin'], 'Self.')['ok'], 'not your own request');

        $this->assertTrue($returns->approveRefund($r->fresh(), $this->makeSuperAdmin(), 'Checked.')['ok']);
        $this->assertSame(MarketplaceReturn::REFUNDED, $r->fresh()->status);
    }

    public function test_the_restaurant_sees_the_problem_and_what_it_paid(): void
    {
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);
        $r = $this->report($s);
        $returns->decide($r, $s['admin'], true, 'Missing.', null, 'restaurant');
        $returns->requestRefund($r->fresh(), $s['admin'], 'Missing meal.');

        Livewire::actingAs($s['owner']->user)->test(\App\Livewire\Seller\Orders::class)->set('tab', 'returns')
            ->assertSee('Problems')
            ->assertSee('Item missing: One meal was not in the bag.')
            ->assertSee("Restaurant's fault")
            ->assertSee('Taken from this order\'s earnings: ₹100.00', false);
    }

    public function test_the_admin_queue_shows_food_problems_with_the_fault_picker_and_rider_strikes(): void
    {
        $s = $this->deliveredFoodOrder();
        $r = $this->report($s);

        Livewire::actingAs($s['admin'])->test(ReturnsQueue::class)
            ->set('statusFilter', 'food')
            ->assertSee('Item missing')
            ->assertSee('0 strike(s) in 30 days')
            ->call('open', $r->id)
            ->assertSee('Damaged on the way — restaurant pays 0%, rider gets a strike')
            ->set('note', 'Photo shows it.')
            ->set('fault', 'restaurant')
            ->call('act', 'accept')
            ->assertSet('flashType', 'success');

        $this->assertSame('restaurant', $r->fresh()->fault);
    }
}
