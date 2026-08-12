<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Widens payments.purpose to add 'plan_subscription' — plan purchases reuse
// this exact table + the Razorpay order/webhook path already generalized
// for wallet top-ups (migration 025000), not a new payment record type.
return new class extends Migration
{
    public function up(): void
    {
        // Was a raw `ALTER TABLE payments MODIFY purpose ENUM(...)` — the
        // docblock this replaced said Laravel's schema builder couldn't
        // widen a MySQL enum without doctrine/dbal, which was true in older
        // Laravel but not since v9's native column-alteration support.
        // change() compiles to the equivalent ALTER on MySQL and to
        // SQLite's own table-rebuild strategy on SQLite — the raw version
        // made this migration (and the whole chain behind it) impossible to
        // run on sqlite, which the automated test suite uses per
        // phpunit.xml. Same resulting column on MySQL; no production impact
        // since this migration has already run for real.
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription'])->default('booking')->change();
        });
    }

    public function down(): void
    {
        DB::table('payments')->where('purpose', 'plan_subscription')->update(['purpose' => 'booking']);

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', ['booking', 'wallet_topup'])->default('booking')->change();
        });
    }
};
