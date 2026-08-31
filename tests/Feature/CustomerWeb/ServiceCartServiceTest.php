<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\ServiceCartItem;
use App\Services\Customer\CatalogPresenter;
use App\Services\Customer\ServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * App\Services\Customer\ServiceCartService — the storefront services cart.
 * The merge rule, the active-service guard, the subcategory "visits"
 * grouping, and the fact that the stored price is an advisory estimate, not
 * the charge.
 */
class ServiceCartServiceTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function cart(): ServiceCartService
    {
        return app(ServiceCartService::class);
    }

    public function test_adding_the_same_service_with_the_same_options_and_slot_merges_and_bumps_quantity(): void
    {
        $user = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(), ['name' => 'AC Service']);

        $this->cart()->add($user, $service, options: [], quantity: 1);
        $this->cart()->add($user, $service, options: [], quantity: 2);

        $this->assertSame(1, ServiceCartItem::where('user_id', $user->id)->count());
        $this->assertSame(3, ServiceCartItem::where('user_id', $user->id)->sole()->quantity);
    }

    public function test_a_different_option_selection_is_a_separate_line(): void
    {
        $user = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());

        $this->cart()->add($user, $service, options: ['5' => [10]]);
        $this->cart()->add($user, $service, options: ['5' => [11]]);

        $this->assertSame(2, ServiceCartItem::where('user_id', $user->id)->count());
    }

    public function test_option_order_does_not_prevent_a_merge(): void
    {
        $user = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());

        $this->cart()->add($user, $service, options: ['5' => [11, 10], '2' => 7]);
        $this->cart()->add($user, $service, options: ['2' => [7], '5' => [10, 11]]);

        $this->assertSame(1, ServiceCartItem::where('user_id', $user->id)->count());
        $this->assertSame(2, ServiceCartItem::where('user_id', $user->id)->sole()->quantity);
    }

    public function test_quantity_update_to_zero_removes_the_line(): void
    {
        $user = $this->makeCustomer();
        $item = $this->cart()->add($user, $this->makeService($this->makeCategory()));

        $this->cart()->updateQuantity($item, 0);

        $this->assertModelMissing($item);
    }

    public function test_clear_empties_only_that_users_cart(): void
    {
        $me = $this->makeCustomer();
        $other = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());

        $this->cart()->add($me, $service);
        $this->cart()->add($other, $service);

        $this->cart()->clear($me);

        $this->assertSame(0, ServiceCartItem::where('user_id', $me->id)->count());
        $this->assertSame(1, ServiceCartItem::where('user_id', $other->id)->count());
    }

    public function test_an_inactive_service_cannot_be_added(): void
    {
        $user = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(), ['is_active' => false]);

        $this->expectException(\RuntimeException::class);
        $this->cart()->add($user, $service);
    }

    public function test_a_non_service_module_service_cannot_be_added(): void
    {
        $user = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(['module' => 'commerce']));

        $this->expectException(\RuntimeException::class);
        $this->cart()->add($user, $service);
    }

    public function test_lines_group_into_visits_by_subcategory_with_category_fallback(): void
    {
        $user = $this->makeCustomer();
        $applianceCat = $this->makeCategory(['name' => 'AC & Appliance Repair']);
        $acSub = $this->makeSubcategory($applianceCat, ['name' => 'AC Repair']);

        $this->cart()->add($user, $this->makeService($applianceCat, ['subcategory_id' => $acSub->id, 'name' => 'Window AC Service']));
        $this->cart()->add($user, $this->makeService($applianceCat, ['subcategory_id' => $acSub->id, 'name' => 'Fridge Repair']));
        // No subcategory -> its own group, keyed on the category.
        $this->cart()->add($user, $this->makeService($this->makeCategory(['name' => 'Plumbing']), ['name' => 'Leaky Tap']));

        $groups = $this->cart()->groupedForUser($user);

        $this->assertCount(2, $groups);
        $ac = $groups->firstWhere('label', 'AC Repair');
        $this->assertNotNull($ac);
        $this->assertSame(2, $ac['item_count']);
        $this->assertNotNull($groups->firstWhere('label', 'Plumbing'));
    }

    public function test_stored_price_is_the_catalog_estimate_not_an_authoritative_charge(): void
    {
        $user = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(), ['base_price' => 777]);

        $item = $this->cart()->add($user, $service);

        $estimate = app(CatalogPresenter::class)->card($service->fresh())['price'];
        $this->assertEquals((float) $estimate, (float) $item->unit_price_estimate);
    }

    public function test_line_count_sums_quantities(): void
    {
        $user = $this->makeCustomer();
        $cat = $this->makeCategory();

        $this->cart()->add($user, $this->makeService($cat, ['name' => 'A']), quantity: 2);
        $this->cart()->add($user, $this->makeService($cat, ['name' => 'B']), quantity: 3);

        $this->assertSame(5, $this->cart()->lineCount($user));
    }

    public function test_update_schedule_changes_the_slot(): void
    {
        $user = $this->makeCustomer();
        $item = $this->cart()->add($user, $this->makeService($this->makeCategory()));
        $when = Carbon::parse('2026-09-10 09:00');

        $this->cart()->updateSchedule($item, $when);

        $this->assertTrue($item->fresh()->scheduled_at->equalTo($when));
    }
}
