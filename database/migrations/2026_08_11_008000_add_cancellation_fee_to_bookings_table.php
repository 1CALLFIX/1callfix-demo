<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The monetary outcome of a cancellation, same role price_final plays for
// a completed booking — set once, by CancellationService, when
// AdminCancelBookingAction runs. Nullable: null means "never cancelled",
// not "cancelled for free" (that's 0.00).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('cancellation_fee', 10, 2)->nullable()->after('cancellation_note');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('cancellation_fee');
        });
    }
};
