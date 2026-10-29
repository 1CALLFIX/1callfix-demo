<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-commerce Phase 5 — compliance (owner 2026-10-28: build it now, rates as Super Admin settings left unset; the CA
 * fills them in). The seller's GSTIN / PAN on the shop (printed on their tax invoice), and on each settlement the
 * GST TCS and income-tax TDS (194-O) the platform withholds from the seller's goods share. Both rates unset = 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('gstin', 15)->nullable()->after('phone');
            $table->string('pan', 10)->nullable()->after('gstin');
        });
        Schema::table('marketplace_settlements', function (Blueprint $table) {
            $table->decimal('tcs_gst_amount', 10, 2)->default(0)->after('goods_franchise');
            $table->decimal('tds_income_tax_amount', 10, 2)->default(0)->after('tcs_gst_amount');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_settlements', fn (Blueprint $table) => $table->dropColumn(['tcs_gst_amount', 'tds_income_tax_amount']));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['gstin', 'pan']));
    }
};
