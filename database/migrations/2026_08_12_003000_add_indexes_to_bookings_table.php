<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DB-hardening finding: assessed status, payment_status, payment_method,
// scheduled_at, completed_at per the audit's request. Grepped actual query
// usage across app/ rather than indexing all five on assumption:
//
// - status: real, frequent WHERE/whereIn/groupBy usage — Dashboard's
//   real-time KPI queries (Livewire/Dashboard.php), DispatchService's
//   busy-provider lookup, the admin Bookings list's status filter. Indexed.
// - completed_at: real WHERE usage — Dashboard's "completed today"
//   count/revenue queries (`where('completed_at', '>=', $today)`). Indexed.
// - payment_status, payment_method, scheduled_at: written to (set on
//   create/completion/cancellation) but never appear in a WHERE, whereIn,
//   or orderBy anywhere in the current codebase. No index added for these —
//   "add only justified indexes based on actual query usage" cuts both
//   ways. Revisit if/when a real filter on these ships.
//
// Short explicit names throughout (well under MySQL's 64-char identifier
// limit) per the prior entitlement_balances lesson.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('status', 'bookings_status_index');
            $table->index('completed_at', 'bookings_completed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_status_index');
            $table->dropIndex('bookings_completed_at_index');
        });
    }
};
