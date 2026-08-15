<?php

namespace App\Services\Catalog;

use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Support\Str;

/** See CatalogImporter for the shared pipeline. */
class SubcategoryImporter extends CatalogImporter
{
    protected function modelClass(): string
    {
        return ServiceSubcategory::class;
    }

    public function entityType(): string
    {
        return 'subcategories';
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
            return ['error' => ['field' => 'category_id', 'message' => "category_id {$categoryRaw} does not exist — import categories first, or check the id."]];
        }

        $image = $this->blankToNull($row['image'] ?? null);
        if (! $this->validateImageReference($image)) {
            return ['error' => ['field' => 'image', 'message' => "'{$image}' is not a valid image URL or path."]];
        }

        $externalId = $this->resolveOwnExternalId($row);

        $attributes = [
            'category_id' => $categoryId,
            'name' => $name,
            'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
            'image' => $image,
            'icon' => $this->blankToNull($row['icon'] ?? null),
            'description' => $this->blankToNull($row['description'] ?? null),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];

        $possibleDuplicate = false;
        if ($externalId === null || ! ServiceSubcategory::where('external_id', $externalId)->exists()) {
            $possibleDuplicate = ServiceSubcategory::where('category_id', $categoryId)->where('name', $name)->exists();
        }

        return ['external_id' => $externalId, 'attributes' => $attributes, 'possible_duplicate' => $possibleDuplicate];
    }

    protected function generateSlug(string $name): string
    {
        return Str::slug($name).'-'.Str::random(4);
    }
}
