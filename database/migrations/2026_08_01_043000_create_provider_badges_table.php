<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P3
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('badge'); // gold, silver, top_rated
            $table->timestamp('awarded_at');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_badges');
    }
};
