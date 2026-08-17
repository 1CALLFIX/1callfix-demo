<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). Column shapes verified against
 * 6amMart's real `items` table. `stock` stays a single integer here
 * (matching real evidence -- even 6amMart's own schema keeps base stock
 * as one column) for products with no variants; a variant's own `stock`
 * (product_variants table, next migration) takes over once one exists.
 * `is_approved` is a real multi-vendor moderation gate (6amMart evidence),
 * defaulting `true` since a single-operator-approved franchise structure
 * doesn't need pre-moderation by default -- the column and the admin
 * toggle are real, not decorative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('images')->nullable();

            $table->decimal('price', 10, 2);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->nullable();

            $table->integer('stock')->default(0); // authoritative only when the product has no variants
            $table->unsignedInteger('maximum_cart_quantity')->nullable();

            $table->boolean('is_approved')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'is_active']);
            $table->index('marketplace_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
