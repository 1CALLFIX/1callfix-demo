<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coupon engine C1 (design §4) — target_type is a registry key (string), not an enum.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('operator', 8)->default('include'); // include|exclude
            $table->json('params')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'target_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_targets');
    }
};
