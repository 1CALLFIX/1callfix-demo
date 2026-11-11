<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hotels H2: a hotel's own weekend price (nights set in Hotel Settings), so it need not edit the calendar each week. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            $table->decimal('weekend_rate', 10, 2)->nullable()->after('nightly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            $table->dropColumn('weekend_rate');
        });
    }
};
