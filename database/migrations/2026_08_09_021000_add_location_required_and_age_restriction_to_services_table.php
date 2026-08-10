<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two flags carried over from Glover's service form, both genuinely useful
// for home services:
//
// `location_required` — whether booking this service needs the customer's
// address. True for essentially everything we do today (an engineer has to
// physically arrive somewhere), hence the default, but a future remote /
// advisory service type wouldn't need one, and the booking flow shouldn't
// have to special-case that by service name.
//
// `age_restriction` — flags a service the customer must confirm they're a
// legal adult to buy. Default false; nothing in the Service vertical needs
// it yet, but it costs nothing to carry and is awkward to retrofit once
// bookings exist.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('location_required')->default(true)->after('is_active');
            $table->boolean('age_restriction')->default(false)->after('location_required');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['location_required', 'age_restriction']);
        });
    }
};
