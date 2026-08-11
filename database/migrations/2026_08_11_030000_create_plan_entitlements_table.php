<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a plan grants — one plan can have several rows (a hybrid plan, e.g.
// "500 deliveries + ₹50,000 allowance", is just two rows, not a new type).
// consumption_trigger is set explicitly per entitlement rather than assumed,
// per the approved plan's amendment 6 ("do not guess silently").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->enum('entitlement_type', [
                'quantity', 'monetary_allowance', 'percentage_discount', 'fixed_discount',
                'fee_waiver', 'member_price', 'commission_reduction', 'commission_override',
                'priority', 'feature_access',
            ]);
            $table->string('module')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('monetary_value', 10, 2)->nullable();
            $table->decimal('percentage_value', 5, 2)->nullable();
            $table->enum('usage_period', ['per_transaction', 'daily', 'monthly', 'pooled_monthly'])->default('monthly');
            $table->enum('consumption_trigger', [
                'booking_created', 'booking_confirmed', 'provider_assigned',
                'payment_completed', 'service_completed', 'module_specific',
            ])->default('booking_created');
            $table->enum('rollover_policy', ['none', 'partial', 'full'])->default('none');
            $table->integer('rollover_cap')->nullable();
            $table->unsignedInteger('rollover_expiry_days')->nullable();
            $table->boolean('overage_enabled')->default(false);
            $table->enum('overage_rate_type', ['flat', 'percentage_of_payg'])->nullable();
            $table->decimal('overage_rate_value', 10, 2)->nullable();
            // commission_override is unusable until BOTH this flag is true on the
            // entitlement AND an admin has actually approved it — no approval
            // workflow UI is built in Phase A, this is a manual Super-Admin toggle.
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            $table->index(['plan_id', 'entitlement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
    }
};
