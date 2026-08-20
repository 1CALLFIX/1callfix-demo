<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin Command Center mission — PayoutService::request() and
// Payouts\Manage now support a 'field_worker' payee (payee_id =
// field_workers.id): CommissionService has credited a FieldWorker's
// wallet identically to a Provider's since Parcel/Taxi/Marketplace
// shipped (applyForFieldWorkerOrder() treats both as the same
// "individual earner"), but there was no way to turn that balance into a
// payout request until now — payouts.payee_type's own DB enum was the
// last thing still hard-blocking it. Same native change() approach as
// 2026_08_11_015000 (users.role enum widening) — runs on both MySQL and
// SQLite (the automated suite's driver per phpunit.xml), no doctrine/dbal
// needed since Laravel 9+.
return new class extends Migration
{
    private const OLD = ['provider', 'franchise_owner'];
    private const NEW = ['provider', 'field_worker', 'franchise_owner'];

    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->enum('payee_type', self::NEW)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->enum('payee_type', self::OLD)->change();
        });
    }
};
