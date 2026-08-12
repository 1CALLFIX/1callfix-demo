<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DB-hardening finding: commissions.booking_id had a plain constrained()
// FK but no uniqueness constraint -- application-level idempotency
// (CommissionService) is the only thing enforcing "one booking -> one
// commission record". This adds the DB-level backstop.
//
// Verified safe before writing this migration: production `commissions`
// has 0 rows (read-only check via tinker, this session) -- no existing
// duplicates, nothing to reconcile or lose.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->unique('booking_id', 'commissions_booking_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_booking_id_unique');
        });
    }
};
