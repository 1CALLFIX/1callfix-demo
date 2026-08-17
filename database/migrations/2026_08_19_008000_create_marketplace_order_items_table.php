<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). `product_id`/`product_variant_id`
 * nullable + nullOnDelete -- a sold order line must survive its catalog
 * row being deleted later. `*_name_snapshot` matches real 6amMart evidence
 * (`order_details.item_details`/`variant` are exactly this kind of
 * point-in-time snapshot), preserving order-history accuracy independent
 * of later catalog edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->json('add_ons_snapshot')->nullable(); // [{name, price}, ...]

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('add_ons_total', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2);

            $table->timestamps();

            $table->index('marketplace_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_items');
    }
};
