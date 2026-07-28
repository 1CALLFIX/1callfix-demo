<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P2
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protection_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. Prime Silver
            $table->string('slug')->unique();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('duration_months')->default(12);
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protection_plans');
    }
};
