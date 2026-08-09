<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dedicated subcategories table, matching Glover's real, proven pattern
// (categories -> subcategories as two separate tables, not a self-referencing
// single table). This structurally guarantees exactly two levels — no risk
// of accidentally nesting a subcategory under a subcategory.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_subcategories');
    }
};
