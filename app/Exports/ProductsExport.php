<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Full-catalog xlsx backup/template for Products — same purpose as
 * CategoriesExport/SubcategoriesExport/ServicesExport (re-importable via
 * ProductImporter), distinct from Products\Manage::exportProductsCsv()'s
 * filtered CSV. No historical Glover sheet to match column names against
 * (Marketplace is a fresh Phase 24 build) — column names are simply this
 * schema's own.
 */
class ProductsExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private bool $templateOnly = false)
    {
    }

    public function collection()
    {
        if ($this->templateOnly) {
            return collect();
        }

        return Product::with(['store', 'category'])->orderBy('name')->get();
    }

    public function headings(): array
    {
        return [
            'id', 'external_id', 'name', 'description', 'store_id', 'marketplace_category_id',
            'price', 'tax_percent', 'discount_percent', 'stock', 'is_active', 'is_approved', 'image', 'slug',
        ];
    }

    /** `id`/`store_id`/`marketplace_category_id` are informational only on export — see CategoriesExport::map()'s docblock. */
    public function map($product): array
    {
        return [
            $product->id,
            $product->external_id,
            $product->name,
            $product->description,
            $product->store_id,
            $product->marketplace_category_id,
            $product->price,
            $product->tax_percent,
            $product->discount_percent,
            $product->stock,
            $product->is_active ? 1 : 0,
            $product->is_approved ? 1 : 0,
            $product->images[0] ?? null,
            $product->slug,
        ];
    }
}
