<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). A real, relational improvement over
 * 6amMart's own flat JSON `variations`/`choice_options` columns -- see
 * PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md §3: the roadmap's own
 * "Variants -> Inventory" being two distinct steps is real evidence for a
 * table that can carry its own per-variant stock count safely under
 * concurrent decrement, which a JSON blob cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('name'); // e.g. "Large / Red"
            $table->string('sku')->nullable();
            $table->decimal('price_override', 10, 2)->nullable(); // falls back to products.price when null
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
