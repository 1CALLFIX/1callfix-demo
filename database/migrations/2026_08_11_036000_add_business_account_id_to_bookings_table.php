<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Schema-only for Phase A, per amendment 9's own caveat: this column exists
// so a future Parcel/business-initiated booking flow can attribute a
// booking to a Business Account, but nothing in Phase A sets it — the
// Service module's CreateBookingAction never populates it. Not building
// Parcel booking functionality merely to justify this column existing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('business_account_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['business_account_id']);
            $table->dropColumn('business_account_id');
        });
    }
};
