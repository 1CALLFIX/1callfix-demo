<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A3 — follow-up to 2026_10_04_120000 (A2). Additive only.
 *
 *  - booking_disputes: where the refund goes (`refund_destination` original|wallet, with a note when an admin sends an
 *    online payment to the wallet because the customer agreed), the gateway refund id, and who bears it
 *    (`bearer` provider|company|split with the two shares; admin must choose, no default) plus how much of the
 *    provider's share has already been taken from their wallet.
 *  - provider_dispute_debts: the part of a provider's share their wallet could not cover. Swept at payout time and
 *    recovered through the wallet ledger, never by a direct balance edit. restrictOnDelete on both keys: a financial
 *    record can never disappear with its dispute or provider.
 *
 * down(): REFUSES while any dispute or declared interim amount exists (financial records), the same pattern as
 * 2026_10_02_003000 (credit_note). It runs first on a rollback, so the A2 tables cannot be reached while data exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_disputes', function (Blueprint $t) {
            $t->string('refund_destination', 12)->nullable();
            $t->text('refund_wallet_choice_note')->nullable();
            $t->string('refund_gateway_id')->nullable();
            $t->string('bearer', 12)->nullable();
            $t->decimal('provider_share', 10, 2)->nullable();
            $t->decimal('company_share', 10, 2)->nullable();
            $t->decimal('provider_recovered', 10, 2)->default(0);
        });

        Schema::create('provider_dispute_debts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_dispute_id')->unique()->constrained('booking_disputes')->restrictOnDelete();
            $t->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $t->decimal('amount_owed', 10, 2);
            $t->decimal('amount_settled', 10, 2)->default(0);
            $t->enum('status', ['outstanding', 'settled'])->default('outstanding');
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        $disputes = DB::table('booking_disputes')->count();
        $declared = DB::table('bookings')->whereNotNull('interim_amount')->count();

        if ($disputes > 0 || $declared > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$disputes} dispute(s) and {$declared} booking(s) with a declared work amount exist. "
                .'These are financial records. Export and remove them deliberately first, then roll back.'
            );
        }

        Schema::dropIfExists('provider_dispute_debts');

        Schema::table('booking_disputes', function (Blueprint $t) {
            $t->dropColumn([
                'refund_destination', 'refund_wallet_choice_note', 'refund_gateway_id',
                'bearer', 'provider_share', 'company_share', 'provider_recovered',
            ]);
        });
    }
};
