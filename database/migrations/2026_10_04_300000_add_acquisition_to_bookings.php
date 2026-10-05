<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F1 (ad readiness): first-touch marketing attribution saved on the booking. Additive and nullable.
// Display and reporting only; never read by pricing, permissions or eligibility.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('acquisition')->nullable()->after('customer_note');
        });
    }

    public function down(): void
    {
        // Same rollback guard as A2/A3: refuse to drop attribution data.
        if (DB::table('bookings')->whereNotNull('acquisition')->exists()) {
            throw new RuntimeException('bookings.acquisition holds data; refusing to drop it.');
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('acquisition');
        });
    }
};
