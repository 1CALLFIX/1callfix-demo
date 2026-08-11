<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B0.3 — schema-only foundation work. Adds a nullable polymorphic
// pair (notifiable_type/notifiable_id) alongside the existing provider_id
// column, WITHOUT touching provider_id's meaning, without backfilling it,
// and without any code reading/writing these new columns yet.
// ServiceMatchingJob/DispatchService/AcceptBookingAction keep working
// exactly as before, unmodified — this migration exists purely so a future,
// separately-approved phase can start using notifiable_type/id for a
// second actor type (e.g. FieldWorker, for platform-direct Parcel/Taxi
// dispatch) without a second schema change at that point. Activating
// polymorphic dispatch behavior is explicitly NOT part of B0.3.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_attempts', function (Blueprint $table) {
            $table->string('notifiable_type')->nullable()->after('provider_id');
            $table->unsignedBigInteger('notifiable_id')->nullable()->after('notifiable_type');
            $table->index(['notifiable_type', 'notifiable_id'], 'dispatch_attempts_notifiable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_attempts', function (Blueprint $table) {
            $table->dropIndex('dispatch_attempts_notifiable_idx');
            $table->dropColumn(['notifiable_type', 'notifiable_id']);
        });
    }
};
