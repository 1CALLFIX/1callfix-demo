<?php

namespace App\Services\Catalog;

use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * See CatalogImporter for the shared pipeline (validate -> classify ->
 * commit -> audit). Products differ from Categories/Subcategories/Services
 * in one real way: they are FRANCHISE-scoped, through their owning store
 * (see Products\Manage::scopeColumns()) — the other three are global
 * catalog with nothing to scope. $actor is threaded through the
 * constructor (not a CatalogImporter abstract-method param, so the shared
 * base class stays untouched) purely so processRow() can enforce the SAME
 * per-store check Products\Manage::createProduct() already does: a
 * franchise-scoped actor can't import products into a store outside their
 * own grant. Caught here, at PREVIEW time, as a normal per-row rejection
 * with a clear reason — not a silent skip and not deferred to commit time.
 */
class ProductImporter extends CatalogImporter
{
    public function __construct(private ?User $actor = null)
    {
    }

    protected function modelClass(): string
    {
        return Product::class;
    }

    public function entityType(): string
    {
        return 'products';
    }

    protected function processRow(array $row): array
    {
        $name = $this->blankToNull($row['name'] ?? null);
        if ($name === null || ! is_string($name) || mb_strlen($name) > 255) {
            return ['error' => ['field' => 'name', 'message' => 'The name field is required and must be 255 characters or fewer.']];
        }

        $storeRaw = $this->blankToNull($row['store_id'] ?? null);
        if ($storeRaw === null) {
            return ['error' => ['field' => 'store_id', 'message' => 'The store_id field is required.']];
        }

        // Stores have no external_id (Marketplace is a fresh Phase 24
        // build, no historical vendor sheet to reconcile against) — a
        // literal real id lookup, unlike resolveRelationId()'s dual
        // external-id-or-real-id resolution for Categories/Subcategories.
        $store = Store::with('franchise')->find($storeRaw);
        if (! $store) {
            return ['error' => ['field' => 'store_id', 'message' => "store_id {$storeRaw} does not exist."]];
        }

        if ($this->actor && ! $this->actor->hasPermission('products.manage', [
            'zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id,
            'city_id' => $store->franchise?->city_id, 'country_id' => $store->franchise?->country_id,
        ])) {
            return ['error' => ['field' => 'store_id', 'message' => "You do not have permission to import products into store_id {$storeRaw} (outside your assigned scope)."]];
        }

        $categoryId = null;
        $categoryRaw = $this->blankToNull($row['marketplace_category_id'] ?? null);
        if ($categoryRaw !== null) {
            $category = MarketplaceCategory::find($categoryRaw);
            if (! $category) {
                return ['error' => ['field' => 'marketplace_category_id', 'message' => "marketplace_category_id {$categoryRaw} does not exist."]];
            }
            $categoryId = $category->id;
        }

        $priceRaw = $this->blankToNull($row['price'] ?? null);
        if ($priceRaw === null || ! is_numeric($priceRaw) || (float) $priceRaw < 0) {
            return ['error' => ['field' => 'price', 'message' => 'price is required and must be a number 0 or greater.']];
        }

        $taxRaw = $this->blankToNull($row['tax_percent'] ?? null);
        if ($taxRaw !== null && (! is_numeric($taxRaw) || (float) $taxRaw < 0)) {
            return ['error' => ['field' => 'tax_percent', 'message' => 'tax_percent must be a number 0 or greater.']];
        }

        $discountRaw = $this->blankToNull($row['discount_percent'] ?? null);
        if ($discountRaw !== null && (! is_numeric($discountRaw) || (float) $discountRaw < 0 || (float) $discountRaw > 100)) {
            return ['error' => ['field' => 'discount_percent', 'message' => 'discount_percent must be a number between 0 and 100.']];
        }

        $stockRaw = $this->blankToNull($row['stock'] ?? null);
        if ($stockRaw !== null && (! is_numeric($stockRaw) || (int) $stockRaw < 0)) {
            return ['error' => ['field' => 'stock', 'message' => 'stock must be a whole number 0 or greater.']];
        }

        $image = $this->blankToNull($row['image'] ?? null);
        if (! $this->validateImageReference($image)) {
            return ['error' => ['field' => 'image', 'message' => "'{$image}' is not a valid image URL or path."]];
        }

        $externalId = $this->resolveOwnExternalId($row);

        $attributes = [
            'store_id' => $store->id,
            'marketplace_category_id' => $categoryId,
            'name' => $name,
            'description' => $this->blankToNull($row['description'] ?? null),
            'images' => $image ? [$image] : [],
            'price' => (float) $priceRaw,
            'tax_percent' => $taxRaw !== null ? (float) $taxRaw : 0,
            'discount_percent' => $discountRaw !== null ? (float) $discountRaw : null,
            'stock' => $stockRaw !== null ? (int) $stockRaw : 0,
            'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
            'is_approved' => $this->normalizeBool($row['is_approved'] ?? 1),
        ];

        $possibleDuplicate = false;
        if ($externalId === null || ! Product::where('external_id', $externalId)->exists()) {
            $possibleDuplicate = Product::where('store_id', $store->id)->where('name', $name)->exists();
        }

        return ['external_id' => $externalId, 'attributes' => $attributes, 'possible_duplicate' => $possibleDuplicate];
    }

    protected function generateSlug(string $name): string
    {
        return Str::slug($name).'-'.Str::random(6);
    }
}
