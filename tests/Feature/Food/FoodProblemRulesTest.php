<?php

namespace Tests\Feature\Food;

use App\Actions\CompleteMarketplaceOrderAction;
use App\Livewire\Customer\Shop\OrderShow;
use App\Livewire\FoodOrders\Dashboard;
use App\Livewire\MarketplaceReturns\Index as ReturnsQueue;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceReturn;
use App\Models\Setting;
use App\Services\Marketplace\MarketplaceReturnService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Food problem rules (owner 2026-11-03, after the live walk-through and a look at how Swiggy / Zomato / DoorDash do it):
 *  - the restaurant Agrees or Disputes each problem by a Food Settings deadline; no answer counts as Agree;
 *  - a food refund is the food only (never delivery or packaging) and goes to the wallet unless the customer asks for
 *    the bank / card on an online order;
 *  - a customer with many problems in the period gets a red "Repeat" badge, and from a higher count "Report a
 *    problem" is replaced by "Please call support" (they can still order);
 *  - the Food Dashboard lists restaurants with the most "restaurant's fault" problems;
 *  - leftovers: a cash order is paid once handed over; the rating box says "restaurant".
 */
class FoodProblemRulesTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** A delivered food order (2 x ₹100 + ₹30 delivery), paid the given way. */
    private function deliveredFoodOrder(string $method = 'online'): array
    {
        $s = $this->makeMarketplaceOrderScenario('ready', [
            'module' => Modules::FOOD, 'order_type' => 'delivery', 'subtotal' => 200, 'delivery_fee' => 30, 'total_amount' => 230,
            'payment_method' => $method, 'payment_status' => $method === 'cash' ? 'pending' : 'paid', 'delivery_address_id' => null,
        ]);
        $s['store']->update(['module' => Modules::FOOD, 'lat' => 1.0, 'lng' => 1.0]);
        $s['rider'] = $this->makeParcelRiderIn($s['franchise'], $s['zone']);
        $s['order']->update([
            'delivery_address_id' => $this->makeAddress($s['customer'], $s['franchise'], $s['zone'])->id,
            'assigned_worker_id' => $s['rider']->id, 'delivery_otp' => '1234',
        ]);
        $s['order']->items()->create(['product_id' => $s['product']->id, 'product_name_snapshot' => 'Veg Meals', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        app(CompleteMarketplaceOrderAction::class)->execute($s['order']->id, $s['rider'], '1234');
        $s['order'] = $s['order']->fresh('items');
        $s['admin'] = $this->makeSuperAdmin();
        $s['seller'] = $s['store']->provider->user;

        return $s;
    }

    private function report(array $s, bool $toBank = false): MarketplaceReturn
    {
        return app(MarketplaceReturnService::class)->request($s['order'], $s['customer'], 'missing', 'One meal was not in the bag.',
            [$s['order']->items->first()->id => 1], ['returns/x.jpg'], $toBank);
    }

    /** An earlier food problem by the same customer on another of their food orders. */
    private function earlierProblem(array $s, string $status = MarketplaceReturn::REFUNDED, int $daysAgo = 10): MarketplaceReturn
    {
        $order = MarketplaceOrder::create([
            'code' => 'FD-'.random_int(100000, 999999), 'franchise_id' => $s['franchise']->id, 'zone_id' => $s['zone']->id,
            'customer_id' => $s['customer']->id, 'store_id' => $s['store']->id, 'module' => Modules::FOOD, 'order_type' => 'delivery',
            'subtotal' => 100, 'total_amount' => 100, 'status' => 'completed', 'payment_status' => 'paid', 'payment_method' => 'online',
        ]);
        $row = MarketplaceReturn::create([
            'marketplace_order_id' => $order->id, 'franchise_id' => $s['franchise']->id, 'store_id' => $s['store']->id,
            'customer_id' => $s['customer']->id, 'reason' => 'missing', 'description' => 'Missing', 'items' => [], 'photos' => [],
            'status' => $status, 'refund_amount' => 100,
        ]);
        $row->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $row;
    }

    public function test_a_food_refund_goes_to_the_wallet_unless_the_customer_asks_for_the_bank(): void
    {
        $s = $this->deliveredFoodOrder('online');
        $this->assertSame('wallet', $this->report($s)->refund_destination, 'online food order: wallet by default');

        MarketplaceReturn::query()->delete();
        $this->assertSame('original', $this->report($s, toBank: true)->refund_destination, 'the customer ticked bank / card');

        Setting::set('food.issue_photo_required', '0');
        MarketplaceReturn::query()->delete();
        Livewire::actingAs($s['customer'])->test(OrderShow::class, ['order' => $s['order']])
            ->set('returning', true)
            ->assertSee('If accepted, the refund goes to your 1CallFix wallet straight away.')
            ->assertSee('Refund to my bank or card instead');
    }

    public function test_a_food_refund_is_the_food_only_never_delivery(): void
    {
        $s = $this->deliveredFoodOrder();
        $r = $this->report($s);
        $returns = app(MarketplaceReturnService::class);

        $result = $returns->decide($r, $s['admin'], true, 'Sorry.', 230, MarketplaceReturn::FAULT_RESTAURANT);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('at most the price of the food (₹200.00)', $result['message']);

        $result = $returns->decide($r, $s['admin'], true, 'Sorry.', 200, MarketplaceReturn::FAULT_RESTAURANT);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Next: request the refund.', $result['message']);
        $this->assertEquals(200, $r->fresh()->refund_amount);
    }

    public function test_the_restaurant_agrees_or_disputes_before_the_deadline_and_silence_counts_as_agree(): void
    {
        Setting::set('food.restaurant_reply_minutes', '30');
        $s = $this->deliveredFoodOrder();
        $r = $this->report($s);
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($r->restaurant_respond_by), 0.1);
        $this->assertSame('waiting', $r->restaurantAnswer());
        $returns = app(MarketplaceReturnService::class);

        // Only this restaurant's owner may answer; a dispute needs a reason.
        $this->assertFalse($returns->respondAsRestaurant($r, $this->makeCustomer(), true)['ok']);
        $this->assertSame('Tell us why you disagree (at least 10 letters).', $returns->respondAsRestaurant($r, $s['seller'], false, 'no')['message']);

        // From the Problems tab.
        Livewire::actingAs($s['seller'])->test(SellerOrders::class)->set('tab', 'returns')
            ->assertSee('Do you agree?')->assertSee('No answer counts as Agree.')
            ->set("disputeNotes.{$r->id}", 'Packed and sealed, photo of the bag taken.')
            ->call('answerProblem', $r->id, 'dispute')
            ->assertSee('Sent. The team will look at both sides and decide.')
            ->assertSee('Your answer: Disputed');
        $r->refresh();
        $this->assertSame(MarketplaceReturn::RESTAURANT_DISPUTED, $r->restaurant_response);
        $this->assertFalse($returns->respondAsRestaurant($r, $s['seller'], true)['ok'], 'one answer only');

        // The admin sees the dispute next to the problem.
        Livewire::actingAs($s['admin'])->test(ReturnsQueue::class)->set('statusFilter', 'food')
            ->assertSee('Restaurant: disputes')->assertSee('Packed and sealed, photo of the bag taken.');

        // A second problem nobody answers: after the deadline it counts as agreed and cannot be answered.
        MarketplaceReturn::query()->delete();
        $late = $this->report($s);
        $this->travel(31)->minutes();
        $this->assertSame('no_reply', $late->fresh()->restaurantAnswer());
        $this->assertSame('The time to answer is over; no answer counts as Agree.', $returns->respondAsRestaurant($late->fresh(), $s['seller'], false, 'It was all packed.')['message']);
        Livewire::actingAs($s['admin'])->test(ReturnsQueue::class)->set('statusFilter', 'food')
            ->assertSee('Restaurant: no answer in time (counts as agree)');
    }

    public function test_a_repeat_customer_is_flagged_and_from_the_pause_count_must_call_support(): void
    {
        Setting::set('food.repeat_flag_count', '2');
        Setting::set('food.repeat_pause_count', '3');
        Setting::set('food.repeat_days', '90');
        $s = $this->deliveredFoodOrder();
        $returns = app(MarketplaceReturnService::class);

        $this->earlierProblem($s, MarketplaceReturn::REFUNDED, 120); // outside the 90 days: not counted
        $this->earlierProblem($s, MarketplaceReturn::REFUNDED, 20);
        $this->report($s); // 2 in 90 days → Repeat

        Livewire::actingAs($s['admin'])->test(ReturnsQueue::class)->set('statusFilter', 'food')
            ->assertSee('Repeat')->assertSee('2 problem(s) in 90 days · 1 accepted · ₹100.00 refunded');

        // A third in the period: the next order's "Report a problem" is replaced by "Please call support".
        $this->earlierProblem($s, MarketplaceReturn::DECLINED, 5);
        MarketplaceReturn::where('marketplace_order_id', $s['order']->id)->delete();
        $this->earlierProblem($s, MarketplaceReturn::RECEIVED, 2); // 3 in 90 days (20, 5 and 2 days ago)
        $this->assertSame('You have reported several problems recently. Please call our support team to report this one.',
            $returns->cannotRequest($s['order']->fresh(), $s['customer']->id));
        Livewire::actingAs($s['customer'])->test(OrderShow::class, ['order' => $s['order']->fresh()])
            ->assertSee('Please call our support team')->assertDontSee('data-testid="problem-open"', false);

        // Blank = never pause.
        Setting::set('food.repeat_pause_count', '');
        $this->assertNull($returns->cannotRequest($s['order']->fresh(), $s['customer']->id));
    }

    public function test_the_dashboard_lists_restaurants_with_the_most_mistakes(): void
    {
        $s = $this->deliveredFoodOrder();
        $r = $this->report($s);
        app(MarketplaceReturnService::class)->decide($r, $s['admin'], true, 'Sorry.', null, MarketplaceReturn::FAULT_RESTAURANT);

        Livewire::actingAs($s['admin'])->test(Dashboard::class)
            ->assertViewHas('restaurantFaults', fn ($rows) => $rows->count() === 1 && (int) $rows->first()->n === 1)
            ->assertSee('mistakes (last 30 days)');
    }

    public function test_a_cash_order_is_paid_once_handed_over_and_the_rating_box_says_restaurant(): void
    {
        $s = $this->deliveredFoodOrder('cash');
        $this->assertSame('paid', $s['order']->payment_status);

        Livewire::actingAs($s['customer'])->test(OrderShow::class, ['order' => $s['order']])
            ->assertSee('Anything to tell the restaurant? (optional)')->assertDontSee('Anything to tell the shop?');
    }
}
