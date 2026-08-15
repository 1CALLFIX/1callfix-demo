<?php

namespace App\Services\Catalog;

use App\Models\ServiceCategory;
use App\Support\Modules;
use Illuminate\Support\Str;

/**
 * See CatalogImporter for the shared pipeline. Historical category sheets
 * (e.g. `1.8.12 categories IMP.xlsx`) carry `vendor_Type_id` (a numeric
 * id), not our `module` slug column — HeadingRowImport already normalizes
 * that header to `vendor_type_id`. Only two mappings are real, confirmed
 * facts from the actual historical data: vendor_type_id 5 = the built
 * Service categories, 9 = the unbuilt commerce/fashion categories (both
 * slugs already exist in App\Support\Modules::ALL). Anything else is a
 * validation error, never a guessed default — the same "don't invent it"
 * discipline this mission applies everywhere else.
 */
class CategoryImporter extends CatalogImporter
{
    private const VENDOR_TYPE_TO_MODULE = [
        5 => Modules::SERVICE,
        9 => 'commerce',
    ];

    protected function modelClass(): string
    {
        return ServiceCategory::class;
    }

    public function entityType(): string
    {
        return 'categories';
    }

    protected function processRow(array $row): array
    {
        $name = $this->blankToNull($row['name'] ?? null);
        if ($name === null || ! is_string($name) || mb_strlen($name) > 255) {
            return ['error' => ['field' => 'name', 'message' => 'The name field is required and must be 255 characters or fewer.']];
        }

        $module = $this->resolveModule($row);
        if (isset($module['error'])) {
            return $module;
        }

        $image = $this->blankToNull($row['image'] ?? null);
        if (! $this->validateImageReference($image)) {
            return ['error' => ['field' => 'image', 'message' => "'{$image}' is not a valid image URL or path."]];
        }

        $externalId = $this->resolveOwnExternalId($row);

        $attributes = [
            'module' => $module['value'],
            'name' => $name,
            'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
            'image' => $image,
            'icon' => $this->blankToNull($row['icon'] ?? null),
            'color' => $this->blankToNull($row['color'] ?? null),
            'description' => $this->blankToNull($row['description'] ?? null),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];

        $possibleDuplicate = false;
        if ($externalId === null || ! ServiceCategory::where('external_id', $externalId)->exists()) {
            $possibleDuplicate = ServiceCategory::where('module', $module['value'])->where('name', $name)->exists();
        }

        return ['external_id' => $externalId, 'attributes' => $attributes, 'possible_duplicate' => $possibleDuplicate];
    }

    /** @return array{error: array}|array{value: string} */
    private function resolveModule(array $row): array
    {
        $moduleCell = $this->blankToNull($row['module'] ?? null);
        if ($moduleCell !== null) {
            if (! in_array($moduleCell, Modules::slugs(), true)) {
                return ['error' => ['field' => 'module', 'message' => "'{$moduleCell}' is not a recognized module. Valid values: ".implode(', ', Modules::slugs())]];
            }

            return ['value' => $moduleCell];
        }

        $vendorTypeId = $this->blankToNull($row['vendor_type_id'] ?? null);
        if ($vendorTypeId !== null) {
            $vendorTypeId = (int) $vendorTypeId;
            if (! isset(self::VENDOR_TYPE_TO_MODULE[$vendorTypeId])) {
                return ['error' => ['field' => 'vendor_type_id', 'message' => "vendor_type_id {$vendorTypeId} has no known module mapping. Provide a 'module' column instead, or extend CategoryImporter::VENDOR_TYPE_TO_MODULE."]];
            }

            return ['value' => self::VENDOR_TYPE_TO_MODULE[$vendorTypeId]];
        }

        return ['value' => Modules::SERVICE];
    }

    protected function generateSlug(string $name): string
    {
        return Str::slug($name).'-'.Str::random(4);
    }
}
