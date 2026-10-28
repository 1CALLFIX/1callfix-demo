<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-commerce Phase 0 (2026-10-25) — one settlement row per completed marketplace order: who earns what, what
 * is held for the return window, and what a cash holder owes back. Kept apart from
 * provider_commission_receivables / field_worker_cash_dues because those are hard-keyed to bookings and
 * parcels, and a shop order splits two ways (goods → seller, delivery fee → rider).
 *
 * payment_mode: online (Razorpay/wallet: platform holds all money), cash_delivery (rider holds the cash),
 * cash_pickup (seller holds the cash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->unique()->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('commission_id')->constrained('commissions')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('field_worker_id')->nullable()->constrained('field_workers')->nullOnDelete();
            $table->string('payment_mode', 20);

            $table->decimal('goods_amount', 10, 2);
            $table->decimal('seller_share', 10, 2);
            $table->decimal('goods_platform', 10, 2);
            $table->decimal('goods_franchise', 10, 2);
            $table->decimal('delivery_amount', 10, 2)->default(0);
            $table->decimal('rider_share', 10, 2)->default(0);
            $table->decimal('delivery_platform', 10, 2)->default(0);
            $table->decimal('delivery_franchise', 10, 2)->default(0);
            // Minimum rider payout (marketplace.rider_min_payout): what was added on top of the rider's share of the
            // fee, e.g. on a free-delivery order. Already included in rider_share. Funded by the platform or the seller.
            $table->decimal('rider_topup', 10, 2)->default(0);
            $table->string('rider_topup_funded_by', 20)->nullable(); // platform | seller

            // Return-window hold on the seller's share (and, for online orders, the franchise's goods share).
            $table->timestamp('release_at');
            $table->timestamp('released_at')->nullable();
            $table->string('status', 20)->default('held'); // held | released

            // Cash held by the rider (cash_delivery) or the seller (cash_pickup) that belongs to others.
            $table->string('cash_debtor_type', 20)->nullable(); // field_worker | provider
            $table->decimal('cash_owed', 10, 2)->default(0);
            $table->decimal('cash_franchise_portion', 10, 2)->default(0);
            $table->decimal('cash_settled', 10, 2)->default(0);
            $table->decimal('cash_franchise_settled', 10, 2)->default(0);
            $table->string('cash_status', 20)->default('none'); // none | outstanding | settled
            $table->timestamp('cash_settled_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'release_at']);
            $table->index(['cash_debtor_type', 'cash_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_settlements');
    }
};
