<?php

namespace App\Services\Catalog;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Support\Str;

/**
 * See CatalogImporter for the shared pipeline. Accepts Glover's original
 * column names as aliases (price -> base_price, duration -> price_type,
 * photo -> cover_image) so a real, unmodified historical export file
 * imports without editing headers — same reasoning ServicesExport's own
 * docblock already documents. `vendor_id`/`created_at`/`updated_at`/
 * `formatted_date` are read (implicitly, via $row) and simply never
 * referenced — services aren't vendor-owned in this schema, and the
 * timestamp columns are Eloquent's own job.
 */
class ServiceImporter extends CatalogImporter
{
    protected function modelClass(): string
    {
        return Service::class;
    }

    public function entityType(): string
    {
        return 'services';
    }

    protected function processRow(array $row): array
    {
        $name = $this->blankToNull($row['name'] ?? null);
        if ($name === null || ! is_string($name) || mb_strlen($name) > 255) {
            return ['error' => ['field' => 'name', 'message' => 'The name field is required and must be 255 characters or fewer.']];
        }

        $categoryRaw = $this->blankToNull($row['category_id'] ?? null);
        if ($categoryRaw === null) {
            return ['error' => ['field' => 'category_id', 'message' => 'The category_id field is required.']];
        }

        $categoryId = $this->resolveRelationId(ServiceCategory::class, $categoryRaw);
        if ($categoryId === null) {
            return ['error' => ['field' => 'category_id', 'message' => "category_id {$categoryRaw} does not exist — import categories first."]];
        }

        $subcategoryId = null;
        $subcategoryRaw = $this->blankToNull($row['subcategory_id'] ?? null);
        if ($subcategoryRaw !== null) {
            $subcategoryId = $this->resolveRelationId(ServiceSubcategory::class, $subcategoryRaw);
            if ($subcategoryId === null) {
                return ['error' => ['field' => 'subcategory_id', 'message' => "subcategory_id {$subcategoryRaw} does not exist — import subcategories first."]];
            }

            // Real referential integrity, not just "does it exist somewhere"
            // — the same rule the dependent dropdown enforces by hand.
            $belongs = ServiceSubcategory::where('id', $subcategoryId)->where('category_id', $categoryId)->exists();
            if (! $belongs) {
                return ['error' => ['field' => 'subcategory_id', 'message' => "subcategory_id {$subcategoryRaw} does not belong to category_id {$categoryRaw}."]];
            }
        }

        // PRICE CHECK — stored exactly as given, never rounded/rescaled.
        $basePriceRaw = $this->blankToNull($row['base_price'] ?? $row['price'] ?? null);
        if ($basePriceRaw === null || ! is_numeric($basePriceRaw) || (float) $basePriceRaw < 0) {
            return ['error' => ['field' => 'base_price', 'message' => 'base_price is required and must be a number 0 or greater.']];
        }

        $discountRaw = $this->blankToNull($row['discount_price'] ?? null);
        if ($discountRaw !== null) {
            if (! is_numeric($discountRaw) || (float) $discountRaw < 0) {
                return ['error' => ['field' => 'discount_price', 'message' => 'discount_price must be a number 0 or greater.']];
            }
            if ((float) $discountRaw >= (float) $basePriceRaw) {
                return ['error' => ['field' => 'discount_price', 'message' => 'discount_price must be less than base_price.']];
            }
        }

        // IMAGE/URL CHECK
        $coverImage = $this->blankToNull($row['cover_image'] ?? $row['photo'] ?? null);
        if (! $this->validateImageReference($coverImage)) {
            return ['error' => ['field' => 'cover_image', 'message' => "'{$coverImage}' is not a valid image URL or path."]];
        }

        $priceTypeRaw = $row['price_type'] ?? $row['duration'] ?? null;
        $externalId = $this->resolveOwnExternalId($row);

        $attributes = [
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'name' => $name,
            'description' => $this->blankToNull($row['description'] ?? null),
            'base_price' => (float) $basePriceRaw,
            'discount_price' => $discountRaw !== null ? (float) $discountRaw : null,
            'price_type' => $this->normalizePriceType($priceTypeRaw),
            'duration_estimate_mins' => (int) ($row['duration_estimate_mins'] ?? 60),
            'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
            'location_required' => $this->normalizeBool($row['location_required'] ?? 1),
            'age_restriction' => $this->normalizeBool($row['age_restriction'] ?? 0),
            'cover_image' => $coverImage,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];

        $possibleDuplicate = false;
        if ($externalId === null || ! Service::where('external_id', $externalId)->exists()) {
            $possibleDuplicate = Service::where('category_id', $categoryId)
                ->where('subcategory_id', $subcategoryId)
                ->where('name', $name)
                ->exists();
        }

        return ['external_id' => $externalId, 'attributes' => $attributes, 'possible_duplicate' => $possibleDuplicate];
    }

    /**
     * Glover's `duration` column holds "fixed" | "starts from" (a
     * price-display type, not a time span) — best-effort mapping, not a
     * contract, so anything unrecognised defaults to fixed rather than
     * failing the row. Same rule Services\Manage::normalizePriceType()
     * already used before this class existed.
     */
    private function normalizePriceType($raw): string
    {
        $value = strtolower(trim((string) $raw));

        return match (true) {
            in_array($value, ['hourly', 'per hour', 'per_hour'], true) => 'hourly',
            in_array($value, ['starts from', 'starts_from', 'quote_on_inspection', 'quote on inspection'], true) => 'quote_on_inspection',
            default => 'fixed',
        };
    }

    protected function generateSlug(string $name): string
    {
        return Str::slug($name);
    }
}
