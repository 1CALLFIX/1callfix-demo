<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel Stage 2 — cash on delivery for shops, through the HQ ledger. The sender (usually a shop) asks the rider to
 * collect the item's price from the recipient at the drop. The rider holds that cash, so it is added to the rider's
 * cash due for the parcel (cod_portion); when the rider has paid that due back to HQ the sender's wallet is credited
 * (cod_remitted_at). Additive only, every new column nullable or defaulted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->decimal('cod_amount', 10, 2)->nullable()->after('pack_credit_returned_at');
            $table->dateTime('cod_collected_at')->nullable()->after('cod_amount');
        });

        Schema::table('field_worker_cash_dues', function (Blueprint $table) {
            $table->decimal('cod_portion', 10, 2)->default(0)->after('franchise_portion');
            $table->dateTime('cod_remitted_at')->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('field_worker_cash_dues', function (Blueprint $table) {
            $table->dropColumn(['cod_portion', 'cod_remitted_at']);
        });
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropColumn(['cod_amount', 'cod_collected_at']);
        });
    }
};
