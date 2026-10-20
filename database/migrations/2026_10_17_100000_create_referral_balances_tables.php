<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-10-17 — referral money is a SEPARATE account from the
 * customer's own wallet (top-ups). It is never withdrawable, can pay only an
 * admin-set percentage of a booking (rest online), and returns here on refund.
 *
 *   referral_balances               one row per user: the current balance.
 *   referral_balance_transactions   the ledger. Credits carry `remaining`
 *                                   (what is still unspent of that grant) and
 *                                   `expires_at` (admin setting, null = never),
 *                                   so spending and expiry can work oldest-first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('balance', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('referral_balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_balance_id')->constrained('referral_balances')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->boolean('is_credit');
            $table->decimal('remaining', 10, 2)->nullable(); // credits only: unspent part of this grant
            $table->timestamp('expires_at')->nullable();     // credits only: null = never expires
            $table->string('reason');
            $table->string('ref')->unique();                 // idempotency key
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Explicit short name: the generated one is 77 characters and MySQL allows 64 (failed on prod 2026-10-20).
            $table->index(['referral_balance_id', 'is_credit', 'expires_at'], 'rbt_balance_credit_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_balance_transactions');
        Schema::dropIfExists('referral_balances');
    }
};
