<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-03: the 1CallFix master product list (database/data/master_catalog.csv, 804 items in 15 categories).
 * A seller adds products from it ("Add from list") and types only the price and stock. Loaded with
 * `php artisan catalog:load-master`; the list is HQ's, sellers never edit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('module', 40)->default('commerce')->index();
            $table->string('category', 80);
            $table->string('sub_category', 80);
            $table->string('name', 150);
            $table->string('phase', 20)->default('Launch'); // Launch | Later
            $table->foreignId('marketplace_category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['module', 'category', 'sub_category', 'name'], 'catalog_items_unique_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
