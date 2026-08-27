<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\ServiceIndex;
use App\Livewire\Customer\Home;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The NEW badge, manual catalog badges, and flash-sale offers as the customer
 * app renders them (Phase C).
 *
 * All three come from engines that already existed — the Badge engine and the
 * Flash Sale engine — so what is under test here is that the customer screens
 * OBEY them, including in every case where the correct answer is to show
 * nothing.
 */
class CatalogBadgeAndOfferTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    // ==================== The NEW badge ====================

    /**
     * NEW is an `automatic` badge with rule `recently_created` and an
     * admin-editable `within_days` (14 by default). It is never persisted —
     * it is evaluated live against the service's own created_at.
     */
    public function test_a_recently_created_service_carries_the_new_badge(): void
    {
        $category = $this->makeCategory();
        $this->ageService($this->makeService($category, ['name' => 'Fresh Service']), 2);

        Livewire::test(ServiceIndex::class)
            ->assertSee('Fresh Service')
            ->assertSee('NEW');
    }

    public function test_a_service_older_than_the_new_window_does_not_carry_the_badge(): void
    {
        $category = $this->makeCategory();
        $this->ageService($this->makeService($category, ['name' => 'Old Service']), 400);

        Livewire::test(ServiceIndex::class)
            ->assertSee('Old Service')
            ->assertDontSee('NEW');
    }

    /**
     * The badge's threshold is admin-configurable data, not a constant in
     * application code — so changing it must change what the catalog shows,
     * with no deploy and no cron run.
     */
    public function test_the_new_window_is_driven_by_the_badge_row_not_by_code(): void
    {
        $category = $this->makeCategory();
        $this->ageService($this->makeService($category, ['name' => 'Thirty Day Service']), 30);

        Livewire::test(ServiceIndex::class)->assertDontSee('NEW');

        \App\Models\Badge::where('key', 'new')->update(['rule_config' => json_encode(['within_days' => 60])]);

        Livewire::test(ServiceIndex::class)->assertSee('NEW');
    }

    public function test_deactivating_the_new_badge_removes_it_from_every_service(): void
    {
        $category = $this->makeCategory();
        $this->ageService($this->makeService($category), 1);

        Livewire::test(ServiceIndex::class)->assertSee('NEW');

        \App\Models\Badge::where('key', 'new')->update(['is_active' => false]);

        Livewire::test(ServiceIndex::class)->assertDontSee('NEW');
    }

    // ==================== Manual badges ====================

    public function test_a_manually_assigned_badge_is_shown(): void
    {
        $category = $this->makeCategory();
        $service = $this->ageService($this->makeService($category, ['name' => 'Popular Service']), 400);
        $this->assignBadge('popular', $service);

        Livewire::test(ServiceIndex::class)->assertSee('POPULAR');
    }

    public function test_an_expired_badge_assignment_is_not_shown(): void
    {
        $category = $this->makeCategory();
        $service = $this->ageService($this->makeService($category), 400);
        $this->assignBadge('popular', $service, 'global', null, now()->subDay());

        Livewire::test(ServiceIndex::class)->assertDontSee('POPULAR');
    }

    /**
     * A zone-scoped badge must be invisible to a customer who has not
     * resolved into that zone — including an anonymous visitor with no zone
     * at all, whose viewer scope is deliberately empty.
     */
    public function test_a_zone_scoped_badge_is_only_shown_inside_that_zone(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        [, , , $otherZone] = $this->makeFranchiseTree();

        $category = $this->makeCategory();
        $service = $this->ageService($this->makeService($category), 400);
        $this->assignBadge('trending', $service, 'zone', $zone->id);

        Livewire::test(ServiceIndex::class)->assertDontSee('TRENDING');

        app(CustomerLocationContext::class)->setZone($otherZone->id);
        Livewire::test(ServiceIndex::class)->assertDontSee('TRENDING');

        app(CustomerLocationContext::class)->setZone($zone->id);
        Livewire::test(ServiceIndex::class)->assertSee('TRENDING');
    }

    // ==================== Offers / flash sales ====================

    public function test_a_live_flash_sale_discounts_the_displayed_price(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'On Sale', 'base_price' => 1000]);
        $this->makeFlashSale([$service], ['discount_type' => 'percent', 'discount_value' => 25]);

        Livewire::test(ServiceIndex::class)
            ->assertSee('750.00')      // the sale price
            ->assertSee('1,000.00')    // struck through as the usual price
            ->assertSee('25% off');
    }

    public function test_the_offers_screen_lists_only_services_with_a_live_sale(): void
    {
        $category = $this->makeCategory();
        $discounted = $this->makeService($category, ['name' => 'Discounted Service']);
        $this->makeService($category, ['name' => 'Full Price Service']);
        $this->makeFlashSale([$discounted]);

        Livewire::test(ServiceIndex::class, ['offersOnly' => true])
            ->assertSee('Discounted Service')
            ->assertDontSee('Full Price Service');
    }

    public function test_a_finished_sale_neither_discounts_nor_lists(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Was On Sale', 'base_price' => 1000]);
        $this->makeFlashSale([$service], [
            'status' => 'completed',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subWeek(),
        ]);

        Livewire::test(ServiceIndex::class)->assertSee('1,000.00')->assertDontSee('750.00');
        Livewire::test(ServiceIndex::class, ['offersOnly' => true])->assertDontSee('Was On Sale');
    }

    public function test_a_draft_sale_neither_discounts_nor_lists(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Draft Sale Service', 'base_price' => 1000]);
        $this->makeFlashSale([$service], ['status' => 'draft', 'starts_at' => null, 'ends_at' => null]);

        Livewire::test(ServiceIndex::class)->assertSee('1,000.00');
        Livewire::test(ServiceIndex::class, ['offersOnly' => true])->assertDontSee('Draft Sale Service');
    }

    public function test_a_sold_out_sale_stops_applying(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Sold Out Service', 'base_price' => 1000]);
        $sale = $this->makeFlashSale([$service], ['total_quantity_limit' => 1]);

        \App\Models\FlashSaleRedemption::create([
            'flash_sale_id' => $sale->id,
            'service_id' => $service->id,
            'user_id' => $this->makeCustomer()->id,
            'original_price' => 1000,
            'final_price' => 800,
            'discount_applied' => 200,
        ]);

        Livewire::test(ServiceIndex::class, ['offersOnly' => true])->assertDontSee('Sold Out Service');
    }

    public function test_the_offers_screen_states_plainly_when_nothing_is_on_offer(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Full Price Service']);

        Livewire::test(ServiceIndex::class, ['offersOnly' => true])
            ->assertSee('No offers running right now')
            ->assertDontSee('Full Price Service');
    }

    public function test_the_homepage_offers_section_disappears_when_no_sale_is_live(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category);

        Livewire::test(Home::class)->assertDontSee('Offers on now');
    }
}
