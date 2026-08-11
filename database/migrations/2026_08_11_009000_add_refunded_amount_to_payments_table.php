<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// payments.status already had 'refunded' as a valid enum value with no
// code ever setting it (confirmed by audit — PaymentController's webhook
// comment explicitly defers "refund.processed" handling). This adds the
// one column needed to record how much of a captured payment was actually
// refunded, alongside that existing status value — not a new refund table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
    }
};
