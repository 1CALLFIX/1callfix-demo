<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tips + waiting/rain/overtime/peak/night compensation (mission Phase 5).
// One audit row per actual payout event -- the real money movement itself
// always goes through WalletService::credit()/debit() (wallet_transactions
// is still the one ledger; this table is the compensation-specific AUDIT
// TRAIL explaining why a given wallet credit happened, same relationship
// PerformanceCampaignParticipant/CommissionService have to wallet_transactions).
// No rate is ever hardcoded here or in CompensationService -- every rate is
// a Setting, defaulted to 0 (no effect) until an admin configures it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['tip', 'waiting', 'rain', 'overtime', 'peak', 'night']);
            $table->decimal('amount', 10, 2);
            // The real inputs the amount was computed from (minutes, rate,
            // window) -- so a later audit never has to guess how a number
            // was reached.
            $table->json('computed_basis')->nullable();
            $table->enum('status', ['applied', 'reversed'])->default('applied');
            // Null for the fully-automatic types (overtime/night/peak,
            // derived straight from booking timestamps); set for manual
            // types (rain/waiting -- no sensor/weather/arrival-time data
            // exists to auto-derive these, see KNOWN_RISKS_AND_DECISIONS.md)
            // and for tips (set to the customer who tipped).
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wallet_transaction_ref')->nullable()->unique();
            $table->timestamps();

            $table->unique(['booking_id', 'type'], 'booking_compensation_unique');
            $table->index(['provider_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_compensations');
    }
};
