<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop go-live (owner 2026-10-31): a rider collects an accepted return from the customer and takes it back to the
 * shop. The rider's fee is paid by the seller out of their held earnings (owner decision); the customer pays nothing.
 * pickup_status: null = no rider pickup (marked received by hand, as before) | open | assigned | picked | dropped | cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->string('pickup_status', 16)->nullable()->after('received_at');
            $table->foreignId('pickup_worker_id')->nullable()->after('pickup_status')->constrained('field_workers')->nullOnDelete();
            $table->decimal('pickup_fee', 10, 2)->nullable()->after('pickup_worker_id');
            $table->decimal('pickup_fee_seller', 10, 2)->nullable()->after('pickup_fee');
            $table->decimal('pickup_fee_platform', 10, 2)->nullable()->after('pickup_fee_seller');
            $table->string('pickup_otp', 8)->nullable()->after('pickup_fee_platform');
            $table->string('drop_otp', 8)->nullable()->after('pickup_otp');
            $table->timestamp('pickup_assigned_at')->nullable()->after('drop_otp');
            $table->timestamp('picked_up_at')->nullable()->after('pickup_assigned_at');
            $table->timestamp('dropped_at')->nullable()->after('picked_up_at');
            $table->index(['pickup_status', 'franchise_id']);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->dropIndex(['pickup_status', 'franchise_id']);
            $table->dropConstrainedForeignId('pickup_worker_id');
            $table->dropColumn(['pickup_status', 'pickup_fee', 'pickup_fee_seller', 'pickup_fee_platform', 'pickup_otp',
                'drop_otp', 'pickup_assigned_at', 'picked_up_at', 'dropped_at']);
        });
    }
};
