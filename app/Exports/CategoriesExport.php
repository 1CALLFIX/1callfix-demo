<?php

namespace App\Exports;

use App\Models\ServiceCategory;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Column order/names deliberately match Glover's real
 * `1.8.12 categories IMP.xlsx` template for the columns both systems share
 * (id, name, is_active, image) — an existing Glover-format sheet imports with
 * zero remapping. icon/description/sort_order are extra columns Glover's
 * format doesn't have; they round-trip our own exports without data loss but
 * are optional on import (default to null/0 if the sheet doesn't have them).
 * No vendor_type_id column — that's Glover's global cross-vertical
 * classifier; this table is already vertical-scoped by name/design (see
 * PROJECT_HANDOFF.md), so it doesn't apply here.
 */
class CategoriesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private bool $templateOnly = false)
    {
    }

    public function collection()
    {
        if ($this->templateOnly) {
            return collect();
        }

        return ServiceCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    public function headings(): array
    {
        return ['id', 'name', 'is_active', 'image', 'icon', 'description', 'sort_order'];
    }

    public function map($category): array
    {
        return [
            $category->id,
            $category->name,
            $category->is_active ? 1 : 0,
            $category->image,
            $category->icon,
            $category->description,
            $category->sort_order,
        ];
    }
}
