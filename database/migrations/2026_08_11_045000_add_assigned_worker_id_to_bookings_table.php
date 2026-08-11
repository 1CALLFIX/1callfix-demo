<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B0.2 — purely additive. bookings.provider_id keeps its existing
// meaning unchanged (the accountable Partner; commission/settlement stays
// tied to it, see CommissionService, untouched). This is a side annotation
// for "who is physically executing it" — see AssignBookingToWorkerAction.
// Requires field_workers (Phase B0.1) to already exist.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('assigned_worker_id')->nullable()->after('provider_id')
                ->constrained('field_workers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['assigned_worker_id']);
            $table->dropColumn('assigned_worker_id');
        });
    }
};
