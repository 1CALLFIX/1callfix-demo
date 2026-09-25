<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — approved migration M4.
 *
 * `ref` gives loyalty_points the same idempotency key wallet_transactions
 * already has: the FIFO expiry job writes `loyalty-expire:{earnRowId}`, and a
 * unique index is what makes a re-run (or two overlapping runs) unable to
 * expire the same lot twice. Nullable — every pre-existing row keeps NULL,
 * and NULLs never collide on a unique index (MySQL and SQLite alike).
 *
 * `actor_id` records the admin behind a compensating adjustment. Additive
 * only, both nullable, clean down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_points', function (Blueprint $table) {
            $table->string('ref')->nullable()->unique()->after('booking_id');
            $table->foreignId('actor_id')->nullable()->after('ref')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_points', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
            $table->dropColumn('actor_id');
            $table->dropUnique(['ref']);
            $table->dropColumn('ref');
        });
    }
};
