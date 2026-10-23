<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel delivery completion (owner decision 2026-10-23: riders on a web area, distance + weight + size
 * pricing, online + wallet + cash, deliver to anyone).
 *
 * - parcel_orders: who hands the parcel over / who receives it, the priced distance, the price breakdown
 *   shown to the customer, and when a rider collected cash.
 * - field_worker_cash_dues: a rider who collects cash keeps it and owes the platform + franchise share.
 *   Same idea as provider_commission_receivables (cash commission collection), kept separate because that
 *   table is hard-keyed to providers and bookings.
 * - modules.is_implemented = true for parcel: the Modules screen can now switch it on. It stays INACTIVE
 *   everywhere until an admin activates it (ModuleActivationService: only `service` is on by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->string('pickup_contact_name', 120)->nullable()->after('dropoff_address_id');
            $table->string('pickup_contact_phone', 20)->nullable()->after('pickup_contact_name');
            $table->string('recipient_name', 120)->nullable()->after('pickup_contact_phone');
            $table->string('recipient_phone', 20)->nullable()->after('recipient_name');
            $table->decimal('distance_km', 8, 2)->nullable()->after('package_size');
            $table->json('price_breakdown')->nullable()->after('price_final');
            $table->timestamp('cash_collected_at')->nullable()->after('picked_up_at');
        });

        Schema::create('field_worker_cash_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_worker_id')->constrained('field_workers')->cascadeOnDelete();
            $table->foreignId('parcel_order_id')->constrained('parcel_orders')->cascadeOnDelete();
            $table->foreignId('commission_id')->constrained('commissions')->cascadeOnDelete();
            $table->decimal('platform_portion', 10, 2);
            $table->decimal('franchise_portion', 10, 2);
            $table->decimal('amount_owed', 10, 2);
            $table->decimal('amount_settled', 10, 2)->default(0);
            $table->decimal('franchise_settled', 10, 2)->default(0);
            $table->enum('status', ['outstanding', 'settled'])->default('outstanding');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // One due per parcel order — backstops CommissionService's own idempotency check.
            $table->unique('parcel_order_id');
            $table->index(['field_worker_id', 'status']);
        });

        // A pickup / drop point typed in on the Send a Parcel page is stored as the customer's address (every
        // parcel FK points at `addresses`) but is not one of their saved addresses: it stays out of the
        // address book and every address picker (Address::scopeListed()).
        Schema::table('addresses', function (Blueprint $table) {
            $table->boolean('is_parcel_only')->default(false)->after('is_default');
        });

        DB::table('modules')->where('code', 'parcel')->update(['is_implemented' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')->where('code', 'parcel')->update(['is_implemented' => false, 'updated_at' => now()]);

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('is_parcel_only');
        });

        Schema::dropIfExists('field_worker_cash_dues');

        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_contact_name', 'pickup_contact_phone', 'recipient_name', 'recipient_phone',
                'distance_km', 'price_breakdown', 'cash_collected_at',
            ]);
        });
    }
};
