<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P3
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_payout_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->decimal('commission_earned', 10, 2);
            $table->enum('status', ['pending','settled'])->default('pending');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_payout_ledger');
    }
};
