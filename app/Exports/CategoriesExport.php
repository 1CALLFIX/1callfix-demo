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
 *
 * `module` is our equivalent of Glover's vendor_type — which vertical the
 * category belongs to. Optional on import and defaults to 'service', so
 * pre-module sheets (and Glover's own files) still import unchanged.
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
        return ['id', 'name', 'module', 'is_active', 'image', 'icon', 'color', 'description', 'sort_order'];
    }

    public function map($category): array
    {
        return [
            $category->id,
            $category->name,
            $category->module,
            $category->is_active ? 1 : 0,
            $category->image,
            $category->icon,
            $category->color,
            $category->description,
            $category->sort_order,
        ];
    }
}
