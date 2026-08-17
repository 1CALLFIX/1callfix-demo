<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE. Same small-dedicated-taxonomy pattern as
 * `property_types` (its own migration's own docblock: deliberately not
 * reusing `service_categories`, which carries baggage that doesn't belong
 * to a different catalog axis). Seeded with the mission brief's own
 * explicit first-class list (hotel/resort/guest_house/homestay/hostel/
 * serviced_apartment) — a real, given requirement, not invented — but the
 * table itself is a normal admin-extensible lookup, not a hardcoded enum,
 * per the brief's own "must remain extensible" instruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['name' => 'Hotel', 'slug' => 'hotel'],
            ['name' => 'Resort', 'slug' => 'resort'],
            ['name' => 'Guest House', 'slug' => 'guest_house'],
            ['name' => 'Homestay', 'slug' => 'homestay'],
            ['name' => 'Hostel', 'slug' => 'hostel'],
            ['name' => 'Serviced Apartment', 'slug' => 'serviced_apartment'],
        ] as $type) {
            DB::table('accommodation_types')->insert(array_merge($type, [
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_types');
    }
};
