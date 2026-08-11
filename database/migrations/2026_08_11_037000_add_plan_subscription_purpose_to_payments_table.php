<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Widens payments.purpose to add 'plan_subscription' — plan purchases reuse
// this exact table + the Razorpay order/webhook path already generalized
// for wallet top-ups (migration 025000), not a new payment record type.
// Raw ALTER since Laravel's schema builder can't widen a MySQL enum's value
// set via ->change() without doctrine/dbal.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY purpose ENUM('booking','wallet_topup','plan_subscription') NOT NULL DEFAULT 'booking'");
    }

    public function down(): void
    {
        DB::statement("UPDATE payments SET purpose = 'booking' WHERE purpose = 'plan_subscription'");
        DB::statement("ALTER TABLE payments MODIFY purpose ENUM('booking','wallet_topup') NOT NULL DEFAULT 'booking'");
    }
};
