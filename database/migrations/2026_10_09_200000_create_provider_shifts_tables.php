<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider shifts (Swiggy-style slots): the platform defines named time windows, a provider picks the ones they will
 * work. Purely additive. Nothing changes until the admin sets provider.shifts.mode to reminder or required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('start_time', 5); // 'HH:MM', wall clock in the provider's franchise timezone
            $table->string('end_time', 5);   // earlier than start_time = runs past midnight
            $table->json('days')->nullable(); // [0..6], 0 = Sunday; null = every day
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('provider_shift_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignId('provider_shift_id')->constrained('provider_shifts')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['provider_id', 'provider_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_shift_selections');
        Schema::dropIfExists('provider_shifts');
    }
};
