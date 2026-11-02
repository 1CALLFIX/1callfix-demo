<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product choices 2026-11-02 (owner: size, colour, units and the other choices a shop and food delivery need).
 *  - products.option_names: the choice types the customer picks, in order, e.g. ["Size","Colour"] or ["Portion"].
 *    Null = a plain product (no choices). Each product_variants row is one combination.
 *  - products.unit_value + unit: what one item is, e.g. 500 + "g", 1 + "kg", 6 + "piece". Shown as "500 g".
 *  - product_variants.options: this combination, e.g. {"Size":"M","Colour":"Red"}.
 *  - product_variants.image: one of the product's own photos, shown when this combination is picked.
 * All columns nullable; existing rows are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('option_names')->nullable()->after('maximum_cart_quantity');
            $table->decimal('unit_value', 10, 3)->nullable()->after('option_names');
            $table->string('unit', 20)->nullable()->after('unit_value');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->json('options')->nullable()->after('name');
            $table->string('image')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['options', 'image']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['option_names', 'unit_value', 'unit']);
        });
    }
};
