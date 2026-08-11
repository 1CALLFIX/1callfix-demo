<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Only ever set on EARN rows (positive points); redemption/expiry rows are
// negative and always count regardless of their own expires_at. balance()
// simply excludes earn-rows whose window has passed from the sum -- v1
// scope deliberately doesn't do FIFO lot-tracking against specific earn
// rows, just "does this earn row still count right now".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_points', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_points', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
