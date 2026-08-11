<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-only — same "ledger is truth" pattern as wallet_transactions /
// loyalty_points. No updated_at: rows are NEVER modified (amendment 7,
// "never mutate history destructively"). A 'reverse' row always points at
// the 'consume' row it reverses via related_usage_ledger_id, rather than
// editing that row. quantity_delta/monetary_delta always represent the
// change to REMAINING balance (consume = negative, reverse/rollover_in =
// positive), so the ledger is directly summable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_entitlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entitlement_balance_id')->nullable()->constrained('entitlement_balances')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('event_type', ['consume', 'reverse', 'adjust', 'expire', 'rollover_in', 'rollover_out']);
            $table->integer('quantity_delta')->default(0);
            $table->decimal('monetary_delta', 10, 2)->default(0);
            $table->boolean('was_overage')->default(false);
            $table->decimal('overage_amount_charged', 10, 2)->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('related_usage_ledger_id')->nullable()->constrained('usage_ledger')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id', 'plan_entitlement_id']);
            $table->index(['booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_ledger');
    }
};
