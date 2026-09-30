<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REF 1CF-ADMIN-ROWACTIONS-001 — reversible "Delete" (archive) for the money records that
// never moved money: failed / stale-pending payments, failed payouts, unpaid or ended
// subscriptions. Additive only; nothing existing changes until an admin archives a row.
return new class extends Migration
{
    private const TABLES = ['payments', 'payouts', 'subscriptions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
