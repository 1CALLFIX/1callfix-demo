<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Export/Import session — Products joins Categories/Subcategories/Services
 * on the shared CatalogImporter engine (see App\Services\Catalog\
 * ProductImporter), which decouples a source file's identity from the real
 * PK via `external_id` — same rationale/shape as the identical migrations
 * for service_categories/service_subcategories/services
 * (2026_08_15_00[234]000). Nullable + unique: existing products (created
 * via the admin form, never imported) simply have no external_id, exactly
 * like this migration's three siblings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
