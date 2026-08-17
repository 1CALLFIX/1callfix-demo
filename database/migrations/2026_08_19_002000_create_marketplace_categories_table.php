<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). Self-referential (`parent_id`),
 * matching 6amMart's real `categories` table exactly -- not this
 * codebase's own separate Category+Subcategory convention
 * (`ServiceCategory`/`ServiceSubcategory`). Deliberately not forced
 * through `ServiceCategory` either, same reasoning Property Rental already
 * used for `PropertyType`: a structurally different domain gets its own
 * taxonomy when real evidence supports a different shape, not the nearest
 * existing table by name alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('marketplace_categories')->cascadeOnDelete();
            $table->string('module')->index();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['module', 'parent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_categories');
    }
};
