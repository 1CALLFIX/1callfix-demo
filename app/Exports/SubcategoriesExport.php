<?php

namespace App\Exports;

use App\Models\ServiceSubcategory;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Matches Glover's `1.8.12 subcategories IMP.xlsx` (id, name, is_active,
 * image, category_id) plus our extra icon/description/sort_order columns.
 * See CategoriesExport for the full rationale.
 */
class SubcategoriesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private bool $templateOnly = false)
    {
    }

    public function collection()
    {
        if ($this->templateOnly) {
            return collect();
        }

        return ServiceSubcategory::orderBy('sort_order')->orderBy('name')->get();
    }

    public function headings(): array
    {
        return ['id', 'external_id', 'name', 'is_active', 'image', 'category_id', 'icon', 'description', 'sort_order'];
    }

    /** `id`/`category_id` are informational only on export now — see CategoriesExport::map()'s docblock. */
    public function map($sub): array
    {
        return [
            $sub->id,
            $sub->external_id,
            $sub->name,
            $sub->is_active ? 1 : 0,
            $sub->image,
            $sub->category_id,
            $sub->icon,
            $sub->description,
            $sub->sort_order,
        ];
    }
}
