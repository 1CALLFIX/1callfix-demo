<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->boolean('service')->default(true);
            $table->boolean('food')->default(false);
            $table->boolean('parcel')->default(false);
            $table->boolean('grocery')->default(false);
            $table->boolean('pharmacy')->default(false);
            $table->boolean('commerce')->default(false);
            $table->boolean('bookings')->default(false);
            $table->timestamps();
            $table->unique('franchise_id');
        });

        DB::table('franchises')->pluck('id')->each(function ($franchiseId) {
            DB::table('franchise_modules')->insert([
                'franchise_id' => $franchiseId,
                'service' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_modules');
    }
};