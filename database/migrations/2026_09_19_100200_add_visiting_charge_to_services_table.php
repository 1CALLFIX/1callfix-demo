<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * services.visiting_charge — the part of a service's price that is the
 * visiting / service-call charge (INCLUDED in base_price, never added on top).
 * The catalog had no such concept, so a "free service visit" had nothing to
 * waive. NULL/0 = the service has no separable visiting charge, and a Free
 * Service Visit is never consumed for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('visiting_charge', 10, 2)->nullable()->after('discount_price');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('visiting_charge');
        });
    }
};
