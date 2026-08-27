<?php

namespace Tests\Feature\CustomerWeb\Support;

use App\Models\Badge;
use App\Models\BadgeAssignment;
use App\Models\Banner;
use App\Models\FlashSale;
use App\Models\FlashSaleTarget;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceOption;
use App\Models\ServiceOptionGroup;
use App\Models\ServiceSubcategory;
use Illuminate\Support\Str;

/**
 * Catalog fixture builders for the Phase C customer-discovery suite.
 *
 * Complements (never replaces) Tests\Feature\Support\BookingFixtureHelpers,
 * which already builds the franchise/zone/customer/booking side of the world.
 * This trait adds only what discovery needs and that trait does not have: a
 * navigable taxonomy, priced option groups, badges, banners and flash sales.
 *
 * Every builder takes explicit arguments rather than reading defaults from
 * somewhere else, so a test that needs an inactive category, a category in
 * another vertical, or a service with no options says so in one line at the
 * top of the test rather than in a shared setUp() nobody reads.
 */
trait CatalogFixtures
{
    protected function makeCategory(array $attributes = []): ServiceCategory
    {
        return ServiceCategory::create(array_merge([
            'module' => 'service',
            'name' => 'Category '.Str::random(6),
            'slug' => 'cat-'.Str::random(10),
            'sort_order' => 1,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeSubcategory(ServiceCategory $category, array $attributes = []): ServiceSubcategory
    {
        return ServiceSubcategory::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Subcategory '.Str::random(6),
            'slug' => 'sub-'.Str::random(10),
            'sort_order' => 1,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeService(ServiceCategory $category, array $attributes = []): Service
    {
        return Service::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Service '.Str::random(6),
            'slug' => 'svc-'.Str::random(10),
            'base_price' => 500,
            'price_type' => 'fixed',
            'duration_estimate_mins' => 60,
            'is_active' => true,
            'location_required' => true,
            'age_restriction' => false,
            'sort_order' => 1,
        ], $attributes));
    }

    /**
     * `created_at` is not fillable, and the automatic NEW badge is evaluated
     * against it — so any test about NEW has to be able to age a service past
     * the badge's own window. Eloquent only auto-assigns created_at on
     * insert, so assigning and saving sticks.
     */
    protected function ageService(Service $service, int $days): Service
    {
        $service->created_at = now()->subDays($days);
        $service->save();

        return $service->fresh();
    }

    /**
     * @param  array<string, float>  $options  option name => price delta
     */
    protected function makeOptionGroup(Service $service, array $options, bool $required = false, bool $multiple = false, string $name = 'Options'): ServiceOptionGroup
    {
        $group = ServiceOptionGroup::create([
            'service_id' => $service->id,
            'name' => $name,
            'is_required' => $required,
            'allow_multiple' => $multiple,
            'sort_order' => 1,
        ]);

        $order = 1;
        foreach ($options as $optionName => $delta) {
            ServiceOption::create([
                'service_option_group_id' => $group->id,
                'name' => $optionName,
                'price_delta' => $delta,
                'sort_order' => $order++,
                'is_active' => true,
            ]);
        }

        return $group->fresh('options');
    }

    protected function makeBanner(string $placement, array $attributes = []): Banner
    {
        return Banner::create(array_merge([
            'title' => 'Banner '.Str::random(6),
            'image' => 'banners/test.png',
            'placement' => $placement,
            'is_active' => true,
            'sort_order' => 1,
        ], $attributes));
    }

    /** Assigns a MANUAL badge. Automatic badges (NEW) are never assigned — they are computed. */
    protected function assignBadge(string $key, Service $service, string $scopeType = 'global', ?int $scopeId = null, ?\Illuminate\Support\Carbon $expiresAt = null): BadgeAssignment
    {
        $badge = Badge::where('key', $key)->firstOrFail();

        return BadgeAssignment::create([
            'badge_id' => $badge->id,
            'badgeable_type' => Service::class,
            'badgeable_id' => $service->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeType === 'global' ? null : $scopeId,
            'starts_at' => now()->subDay(),
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);
    }

    /**
     * A flash sale in whatever lifecycle state the test needs, targeting the
     * given services.
     */
    protected function makeFlashSale(array $services, array $attributes = []): FlashSale
    {
        $sale = FlashSale::create(array_merge([
            'name' => 'Sale '.Str::random(6),
            'customer_title' => 'Test sale',
            'type' => 'weekend_sale',
            'status' => 'live',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'scope_type' => 'global',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'min_final_price' => 0,
        ], $attributes));

        foreach ($services as $service) {
            FlashSaleTarget::create(['flash_sale_id' => $sale->id, 'service_id' => $service->id]);
        }

        return $sale;
    }
}
