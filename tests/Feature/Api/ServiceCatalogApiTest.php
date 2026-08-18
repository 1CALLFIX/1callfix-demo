<?php

namespace Tests\Feature\Api;

use App\Models\FranchiseServicePricing;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * P0 Customer Core API — Service catalog discovery (mission item 1).
 */
class ServiceCatalogApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_categories_endpoint_requires_no_authentication_and_returns_the_contract_envelope(): void
    {
        [$category] = $this->makeCategoryAndService();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.0.id', $category->id);
    }

    public function test_inactive_categories_are_excluded(): void
    {
        [$category] = $this->makeCategoryAndService();
        $category->update(['is_active' => false]);

        $this->getJson('/api/categories')->assertJsonCount(0, 'data');
    }

    public function test_categories_from_other_modules_are_excluded(): void
    {
        [$category] = $this->makeCategoryAndService();
        ServiceCategory::create([
            'module' => 'parcel', 'name' => 'Parcel Cat', 'slug' => 'parcel-cat-'.Str::random(6),
            'image' => 'categories/y.png', 'sort_order' => 1, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/categories')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($category->id, $response->json('data.0.id'));
    }

    public function test_subcategories_endpoint_filters_by_category_id_and_module(): void
    {
        [$category] = $this->makeCategoryAndService();
        $sub = ServiceSubcategory::create([
            'category_id' => $category->id, 'name' => 'Sub', 'slug' => 'sub-'.Str::random(6),
            'image' => 'subcategories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);

        $otherCategory = ServiceCategory::create([
            'module' => 'service', 'name' => 'Other Cat', 'slug' => 'other-cat-'.Str::random(6),
            'image' => 'categories/z.png', 'sort_order' => 2, 'is_active' => true,
        ]);
        ServiceSubcategory::create([
            'category_id' => $otherCategory->id, 'name' => 'Other Sub', 'slug' => 'other-sub-'.Str::random(6),
            'image' => 'subcategories/y.png', 'sort_order' => 1, 'is_active' => true,
        ]);

        $response = $this->getJson("/api/subcategories?category_id={$category->id}")->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($sub->id, $response->json('data.0.id'));
    }

    public function test_services_endpoint_excludes_inactive_services(): void
    {
        [, $service] = $this->makeCategoryAndService();
        [, $inactiveService] = $this->makeCategoryAndService();
        $inactiveService->update(['is_active' => false]);

        $response = $this->getJson('/api/services')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($service->id));
        $this->assertFalse($ids->contains($inactiveService->id));
    }

    public function test_services_endpoint_reports_franchise_override_price_when_franchise_id_given(): void
    {
        [$country, $city, $franchise] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();

        FranchiseServicePricing::create([
            'franchise_id' => $franchise->id, 'service_id' => $service->id,
            'price_override' => 777, 'is_offered' => true,
        ]);

        $response = $this->getJson("/api/services?franchise_id={$franchise->id}")->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $service->id);
        $this->assertEquals(777, $row['effective_price']);

        // No franchise_id -> base_price cascade, not the override.
        $response = $this->getJson('/api/services')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $service->id);
        $this->assertEquals($service->base_price, $row['effective_price']);
    }

    public function test_services_endpoint_does_not_exclude_services_whose_franchise_pricing_row_says_not_offered(): void
    {
        // Real, established behavior (Livewire\Bookings\Index's own service
        // dropdown): is_offered=false only removes the price override, it
        // never removes the service from the list.
        [$country, $city, $franchise] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();

        FranchiseServicePricing::create([
            'franchise_id' => $franchise->id, 'service_id' => $service->id,
            'price_override' => 999, 'is_offered' => false,
        ]);

        $response = $this->getJson("/api/services?franchise_id={$franchise->id}")->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $service->id);
        $this->assertNotNull($row, 'A not-offered service must still appear in the catalog.');
        $this->assertEquals($service->base_price, $row['effective_price'], 'is_offered=false must not apply the override.');
    }
}
