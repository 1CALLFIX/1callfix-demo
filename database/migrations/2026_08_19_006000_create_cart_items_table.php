<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). Matches 6amMart's real flat-row cart
 * shape exactly -- no separate Cart "header" row anywhere in their
 * evidence either, each row IS a line item. A customer can hold
 * simultaneous carts across different stores (`store_id` scopes rows, no
 * cross-store merge -- matches real evidence directly). `unit_price_snapshot`
 * is priced at add-time (matching real evidence) rather than re-derived
 * live on every cart read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->json('add_on_ids')->nullable();
            $table->decimal('unit_price_snapshot', 10, 2);

            $table->timestamps();

            $table->index(['user_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
