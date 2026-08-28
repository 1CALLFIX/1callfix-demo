<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 (Multi-Service Booking — Creation). Minimal, dedicated
 * bundle-create idempotency, the smallest mechanism that satisfies the E1
 * discovery finding that duplicate bundle submission was UNPROTECTED. There
 * is no generic HTTP-request idempotency layer in this codebase (every
 * existing guard — CommissionService per booking, WalletService `ref`,
 * ReviewService's unique booking_id — is a domain-level uniqueness key on
 * the row that must not be duplicated), so this follows that same
 * established pattern: a real DB unique key on the thing that must be
 * unique, not just an application check.
 *
 *  - `idempotency_key` — the caller-supplied key (Idempotency-Key header or
 *    an `idempotency_key` body field). Nullable: a bundle created without a
 *    key has no replay protection, exactly like the single-service
 *    POST /api/bookings path today.
 *  - `request_fingerprint` — sha256 of the normalised request (services +
 *    payment method). Lets a retry with the SAME key but MATERIALLY
 *    DIFFERENT body be rejected instead of silently returning an unrelated
 *    bundle.
 *  - unique(customer_id, idempotency_key) — the authoritative guard. NULL
 *    keys don't collide (standard multi-NULL unique-index behaviour on both
 *    MySQL and SQLite), and the key is scoped to the customer so one
 *    customer's key can never reach another customer's bundle. This is what
 *    closes the TOCTOU window between "look for existing" and "insert", the
 *    same way ReviewService's real unique constraint does for reviews.
 *
 * Purely additive: no existing column changes, no backfill, and every
 * bundle created by any other path keeps `idempotency_key = NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_bundles', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('code');
            $table->string('request_fingerprint', 64)->nullable()->after('idempotency_key');

            $table->unique(['customer_id', 'idempotency_key'], 'booking_bundles_customer_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('booking_bundles', function (Blueprint $table) {
            $table->dropUnique('booking_bundles_customer_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_fingerprint']);
        });
    }
};
