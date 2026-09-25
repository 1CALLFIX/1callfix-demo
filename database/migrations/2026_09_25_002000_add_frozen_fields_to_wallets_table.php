<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — approved migration M1. Wallet freeze
 * (D5): frozen_at NULL = not frozen. Reason and the admin who froze it are
 * kept on the row for display; the full freeze/unfreeze history lives in
 * activity_log. Additive, nullable, clean down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('balance');
            $table->string('frozen_reason', 500)->nullable()->after('frozen_at');
            $table->foreignId('frozen_by')->nullable()->after('frozen_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropForeign(['frozen_by']);
            $table->dropColumn(['frozen_by', 'frozen_reason', 'frozen_at']);
        });
    }
};
