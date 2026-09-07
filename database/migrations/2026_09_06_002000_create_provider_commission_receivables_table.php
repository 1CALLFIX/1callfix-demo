<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash-paid Service bookings: the provider physically collects 100% of the
 * price from the customer, so the platform's and franchise's commission
 * never passes through the payment gateway and cannot be netted out at
 * settlement the way a digital booking's is. Before this table those
 * amounts were computed into `commissions` for reporting but had no
 * collection path, and the provider's own share was ALSO credited to their
 * wallet on top of the cash they held — a double-pay.
 *
 * One row per cash booking: what the provider owes the platform+franchise,
 * how much of it has been recovered, and whether it is cleared. Recovery
 * happens by debiting the provider's wallet at payout-request time (see
 * PayoutService::request → settleCashCommissionReceivables) — the wallet's
 * own no-negative guard is never loosened; whatever the wallet cannot
 * cover stays outstanding here and keeps reducing withdrawable balance.
 *
 * platform_portion / franchise_portion are kept separate so the franchise
 * owner can be paid its proportional share of whatever has actually been
 * recovered from the provider so far — not just once a row is fully
 * settled. `franchise_settled` tracks the cumulative amount already paid
 * to the franchise owner against THIS row, the same "recompute the
 * cumulative target, pay only the delta" role `payments.refunded_amount`
 * plays for BundleSettlementService::reconcileRefund() — so a row that
 * clears across several partial sweeps never overpays or underpays the
 * franchise relative to its true share of what's been recovered to date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_commission_receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_id')->constrained('commissions')->cascadeOnDelete();
            $table->decimal('platform_portion', 10, 2);
            $table->decimal('franchise_portion', 10, 2);
            $table->decimal('amount_owed', 10, 2);
            $table->decimal('amount_settled', 10, 2)->default(0);
            $table->decimal('franchise_settled', 10, 2)->default(0);
            $table->enum('status', ['outstanding', 'settled'])->default('outstanding');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // One receivable per booking — backstops CommissionService's own
            // "Commission row already exists -> no-op" idempotency check.
            $table->unique('booking_id');
            $table->index(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_commission_receivables');
    }
};
