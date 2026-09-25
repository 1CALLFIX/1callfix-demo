<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — approved migration M3. The admin behind
 * a compensating wallet adjustment (`admin-adjust:{uuid}`); NULL for every
 * system-written row. Additive, nullable, clean down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->after('ref')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
            $table->dropColumn('actor_id');
        });
    }
};
