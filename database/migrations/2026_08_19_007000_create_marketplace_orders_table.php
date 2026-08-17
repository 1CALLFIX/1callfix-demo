<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). ONE shared table across Ecommerce/
 * Food/Grocery/Pharmacy -- a deliberate divergence from Phase 22.2's own
 * per-vertical-table precedent, made because the evidence itself points
 * the other way here. See PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md
 * §4: 6amMart's own real `orders` table is genuinely ONE table across all
 * four modules, differentiated by a `module_id`-shaped column on the row
 * itself, unlike Glover's Taxi-vs-generic-Order split that motivated
 * Phase 22.2's Option A for Family A. Two different real reference
 * products made two different real design choices for two structurally
 * different situations -- this follows the evidence for THIS situation
 * rather than mechanically repeating the other one.
 *
 * `status` is this codebase's own compact, evidence-traced FSM (see
 * architecture doc §4a), not 6amMart's own one-timestamp-per-status
 * sprawl. `module` duplicates `stores.module` by design, matching real
 * 6amMart evidence of `module_id` existing on BOTH `stores` and `orders`
 * (useful for direct reporting without a join -- the same reasoning
 * `property_reservations.zone_id` already duplicates `properties.zone_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('module')->index();

            $table->enum('order_type', ['delivery', 'pickup'])->default('delivery');
            $table->foreignId('delivery_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('assigned_worker_id')->nullable()->constrained('field_workers')->nullOnDelete();
            // Single OTP, generated when a delivery rider accepts the offer -- not two like
            // Parcel's pickup_otp/delivery_otp. The store's own pending->ready progression
            // already covers the "handed off to the rider" checkpoint (the rider only ever
            // engages once an order is `ready`), so only ONE real verification point remains:
            // rider -> customer delivery. Same "one OTP, not two" reasoning Taxi (Phase 22.6)
            // already used for the identical reason (a single real handoff moment).
            $table->string('delivery_otp')->nullable();

            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);

            $table->enum('status', ['pending', 'accepted', 'preparing', 'ready', 'completed', 'cancelled'])->default('pending');

            $table->decimal('price_final', 10, 2)->nullable(); // set at completion, same Orderable-precedent shape as price_quoted/price_final elsewhere
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method')->nullable();

            $table->foreignId('cancellation_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancellation_note')->nullable();
            $table->decimal('cancellation_fee', 10, 2)->nullable();

            $table->string('coupon_code')->nullable(); // schema present, real evidence -- NOT wired to live Coupon redemption (item 12 stacking rules undecided)
            $table->decimal('coupon_discount_amount', 10, 2)->nullable();

            $table->text('special_instructions')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['franchise_id', 'zone_id']);
            $table->index(['store_id', 'status']);
            $table->index(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
    }
};
