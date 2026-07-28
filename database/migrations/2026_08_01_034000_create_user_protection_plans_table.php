<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P2
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_protection_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('protection_plan_id')->constrained()->cascadeOnDelete();
            $table->timestamp('purchased_at');
            $table->timestamp('expires_at');
            $table->enum('status', ['active','expired','cancelled'])->default('active');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_protection_plans');
    }
};
