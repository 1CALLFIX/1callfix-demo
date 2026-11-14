<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Function halls (owner 2026-11-14): hall rules the owner taps from the Super Admin's list (Hall Settings), each
 * facility free or paid, what customers may bring from outside (food, caterers, vessels, decorators...), and a
 * booking for more than one day in a row (a 2-day wedding). `days` = 1 for every existing booking; `starts_at` /
 * `ends_at` already span the whole hold, so the overlap check needs no other change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            $table->json('rule_list')->nullable()->after('rules');
            // facility => price: null = free, a number = paid (0 = paid, ask the hall for the price)
            $table->json('facility_charges')->nullable()->after('amenities');
            // outside service => 'yes' | 'fee' | 'no' (outside food, caterers, vessels, decorators, ...)
            $table->json('outside_policy')->nullable()->after('facility_charges');
        });
        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('days')->default(1)->after('event_date');
            $table->date('last_date')->nullable()->after('days');
        });
    }

    public function down(): void
    {
        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->dropColumn(['days', 'last_date']);
        });
        Schema::table('halls', function (Blueprint $table) {
            $table->dropColumn(['rule_list', 'facility_charges', 'outside_policy']);
        });
    }
};
