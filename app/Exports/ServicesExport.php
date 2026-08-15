<?php

namespace App\Exports;

use App\Models\Service;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Matches Glover's `1.8.0 services IMP.xlsx` for the shared concepts (id,
 * name, description, category_id, subcategory_id, discount_price, is_active),
 * with a few deliberate differences:
 *   - `price` -> `base_price` (our column name)
 *   - `duration` -> `price_type` — Glover's `duration` column is really a
 *     price-display type ("fixed" | "starts from"), not a time duration; we
 *     already split that concern properly into price_type + a real
 *     duration_estimate_mins column, so we export with the clearer name.
 *     ServicesImport still accepts a `duration` column as an alias so an
 *     unmodified Glover file imports without editing headers.
 *   - `photo` -> `cover_image`
 *   - no `vendor_id` — services aren't vendor-owned in this schema (global,
 *     centrally-priced catalog, per PROJECT_HANDOFF.md's architecture
 *     decisions), so that column doesn't apply.
 *   - no `created_at`/`updated_at`/`formatted_date` — Eloquent timestamps
 *     already cover the first two; `formatted_date` was a cached display
 *     string in Glover, explicitly flagged in the build spec as safe to drop.
 */
class ServicesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private bool $templateOnly = false)
    {
    }

    public function collection()
    {
        if ($this->templateOnly) {
            return collect();
        }

        return Service::with(['category', 'subcategory'])->orderBy('name')->get();
    }

    public function headings(): array
    {
        return [
            'id', 'external_id', 'name', 'description', 'category_id', 'subcategory_id',
            'base_price', 'discount_price', 'price_type', 'duration_estimate_mins',
            'is_active', 'location_required', 'age_restriction', 'cover_image', 'slug',
        ];
    }

    /** `id`/`category_id`/`subcategory_id` are informational only on export now — see CategoriesExport::map()'s docblock. */
    public function map($service): array
    {
        return [
            $service->id,
            $service->external_id,
            $service->name,
            $service->description,
            $service->category_id,
            $service->subcategory_id,
            $service->base_price,
            $service->discount_price,
            $service->price_type,
            $service->duration_estimate_mins,
            $service->is_active ? 1 : 0,
            $service->location_required ? 1 : 0,
            $service->age_restriction ? 1 : 0,
            $service->cover_image,
            $service->slug,
        ];
    }
}
