<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE. An Accommodation can have multiple room
 * types (Standard/Deluxe/Suite/Dormitory/...). `total_inventory` is the
 * quantity-based room count for this room type (e.g. "10 Deluxe rooms") --
 * the safer, evidence-supported architecture per the mission brief's own
 * instruction: no individual-room-record concept is built (no evidence any
 * operator needs to track *which specific room* a guest is in, only how
 * many of a given type are available on a given date), and the brief
 * itself names quantity-based inventory as an acceptable choice when
 * individual-room tracking isn't evidenced. Extensible if that's ever
 * needed: `hotel_room_availabilities` keys on (room_type_id, date), not on
 * an individual room id, so adding individual-room tracking later would be
 * additive, not a rewrite.
 *
 * No price column here -- a room type's actual sellable price lives on
 * `hotel_rate_plans` (a room type can have multiple rate plans, e.g.
 * "Standard Rate" vs "Breakfast Included," each its own price), per the
 * mission brief's own explicit architecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->unsignedInteger('max_adults')->default(2);
            $table->unsignedInteger('max_children')->default(0);
            $table->string('bed_configuration')->nullable();

            $table->unsignedInteger('total_inventory')->default(1);

            $table->string('cover_image')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['accommodation_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_room_types');
    }
};
