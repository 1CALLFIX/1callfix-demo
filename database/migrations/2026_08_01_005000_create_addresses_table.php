<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->default('Home'); // Home / Work / Other
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address_line');
            $table->string('landmark')->nullable();
            $table->string('city')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
