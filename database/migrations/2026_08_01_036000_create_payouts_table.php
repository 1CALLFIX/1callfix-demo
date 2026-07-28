<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P2
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->enum('payee_type', ['provider','franchise_owner']);
            $table->unsignedBigInteger('payee_id');
            $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['pending','processing','paid','failed'])->default('pending');
            $table->string('gateway_ref')->nullable(); // razorpay route transfer id
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
