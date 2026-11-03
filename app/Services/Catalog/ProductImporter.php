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

        // 2026-11-03 (owner): the shop by its number (store_id) OR by its name / link name (store).
        $storeRaw = $this->blankToNull($row['store_id'] ?? null);
        $storeName = $this->blankToNull($row['store'] ?? null);
        if ($storeRaw === null && $storeName === null) {
            return ['error' => ['field' => 'store', 'message' => 'Give the shop: its name in "store" or its number in "store_id".']];
        }
        if ($storeRaw !== null) {
            $store = Store::with('franchise')->find($storeRaw);
        } else {
            $matches = Store::with('franchise')->where(fn ($q) => $q->where('name', $storeName)->orWhere('slug', $storeName))->get();
            if ($matches->count() > 1) {
                return ['error' => ['field' => 'store', 'message' => "More than one shop is called '{$storeName}'. Use its store_id."]];
            }
            $store = $matches->first();
        }
        if (! $store) {
            return ['error' => ['field' => $storeRaw !== null ? 'store_id' : 'store', 'message' => 'Shop '.($storeRaw ?? "'{$storeName}'").' does not exist.']];
        }

        if ($this->actor && ! $this->actor->hasPermission('products.manage', [
            'zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id,
            'city_id' => $store->franchise?->city_id, 'country_id' => $store->franchise?->country_id,
        ])) {
            return ['error' => ['field' => 'store_id', 'message' => "You do not have permission to import products into store_id {$storeRaw} (outside your assigned scope)."]];
        }

        // The category by its number OR its name ("T-Shirts", or "Clothing > T-Shirts" when a name repeats).
        $categoryId = null;
        $categoryRaw = $this->blankToNull($row['marketplace_category_id'] ?? null);
        $categoryName = $this->blankToNull($row['category'] ?? null);
        if ($categoryRaw !== null) {
            $category = MarketplaceCategory::find($categoryRaw);
            if (! $category) {
                return ['error' => ['field' => 'marketplace_category_id', 'message' => "marketplace_category_id {$categoryRaw} does not exist."]];
            }
            $categoryId = $category->id;
        } elseif ($categoryName !== null) {
            [$categoryId, $error] = $this->categoryByName((string) $categoryName, $store->module);
            if ($error) {
                return ['error' => ['field' => 'category', 'message' => $error]];
            }
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

        // Up to 5 photos: "image" and/or "images", several links separated by | or ;
        $images = collect(preg_split('/[|;\n]+/', trim((string) ($row['images'] ?? '').'|'.(string) ($row['image'] ?? ''))))
            ->map(fn ($v) => trim($v))->filter()->unique()->values();
        foreach ($images as $image) {
            if (! $this->validateImageReference($image)) {
                return ['error' => ['field' => 'image', 'message' => "'{$image}' is not a valid image URL or path."]];
            }
        }
        if ($images->count() > 5) {
            return ['error' => ['field' => 'image', 'message' => 'Up to 5 photos per product.']];
        }

        // What one item is: unit_value + unit (Shop Settings: Units sellers can pick).
        $unitValue = $this->blankToNull($row['unit_value'] ?? null);
        $unit = $this->blankToNull($row['unit'] ?? null);
        if ($unitValue !== null && (! is_numeric($unitValue) || (float) $unitValue <= 0)) {
            return ['error' => ['field' => 'unit_value', 'message' => 'unit_value must be a number above 0 (for example 500 or 1).']];
        }
        if ($unit !== null) {
            $allowed = \App\Support\MarketplaceSettings::lines('marketplace.product_units');
            $match = collect($allowed)->first(fn ($u) => mb_strtolower($u) === mb_strtolower((string) $unit));
            if ($match === null) {
                return ['error' => ['field' => 'unit', 'message' => "Unit '{$unit}' is not in the list: ".implode(', ', $allowed).'.']];
            }
            $unit = $match;
        }

        $externalId = $this->resolveOwnExternalId($row);

        $attributes = [
            'store_id' => $store->id,
            'marketplace_category_id' => $categoryId,
            'name' => $name,
            'description' => $this->blankToNull($row['description'] ?? null),
            'images' => $images->all(),
            'unit_value' => $unitValue !== null ? round((float) $unitValue, 3) : null,
            'unit' => $unit,
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

    /**
     * Owner link rule: a clean, readable slug, -2 / -3 only on a repeat, never a random code. Returning '' lets
     * Product's own creating hook pick it (the same rule as a product added by hand).
     */
    protected function generateSlug(string $name): string
    {
        return '';
    }

    /** [category id, error] for "T-Shirts" or "Clothing > T-Shirts", within the shop's module. */
    private function categoryByName(string $raw, ?string $module): array
    {
        $parts = array_values(array_filter(array_map('trim', explode('>', $raw)), fn ($p) => $p !== ''));
        $name = (string) end($parts);
        $q = MarketplaceCategory::where('name', $name)->when($module, fn ($q) => $q->where('module', $module));
        if (count($parts) > 1) {
            $parentName = $parts[count($parts) - 2];
            $q->whereHas('parent', fn ($p) => $p->where('name', $parentName));
        }
        $found = $q->get();
        if ($found->isEmpty()) {
            return [null, "Category '{$raw}' does not exist."];
        }
        if ($found->count() > 1) {
            return [null, "More than one category is called '{$name}'. Write it as 'Parent > {$name}'."];
        }

        return [$found->first()->id, null];
    }
}
