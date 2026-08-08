<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds Taxi as an 8th toggleable module, completing the full set from the
// original Glover vendor_type list (parcel, food, grocery, pharmacy,
// service, taxi, booking, commerce). Defaults false like every other
// not-yet-built vertical — Taxi genuinely needs its own real-time
// trip/fare engine before this flag means anything functionally.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franchise_modules', function (Blueprint $table) {
            $table->boolean('taxi')->default(false)->after('parcel');
        });
    }

    public function down(): void
    {
        Schema::table('franchise_modules', function (Blueprint $table) {
            $table->dropColumn('taxi');
        });
    }
};
