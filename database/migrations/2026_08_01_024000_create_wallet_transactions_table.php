<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->boolean('is_credit')->default(true);
            $table->string('reason')->nullable();
            $table->string('ref')->unique();
            $table->enum('status', ['successful','pending','failed'])->default('successful');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
