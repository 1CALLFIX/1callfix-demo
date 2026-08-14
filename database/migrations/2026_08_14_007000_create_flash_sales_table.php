<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Flash Sale Engine. One sale, targeting one or more Services (the only
// priced catalog entity that currently exists — see FranchiseServicePricing
// for the existing per-franchise override this integrates alongside, not
// replaces), with its own discount and a lifecycle independent of the
// catalog rows it targets.
//
// `status` is the ADMIN-FACING/audit lifecycle field (draft/scheduled/live/
// paused/completed/cancelled) -- the mission's own explicit instruction
// ("server-side time must remain authoritative, do not rely solely on
// cron") means the ACTUAL applicability of a sale's discount is always
// re-derived at read time from status + starts_at/ends_at together
// (FlashSale::isCurrentlyActive()), never from status alone -- a
// 'scheduled' sale whose starts_at has already passed behaves as live
// immediately, without waiting for a sync command to flip the column.
// 'paused' is the one state that's a pure admin override, deliberately NOT
// time-derived -- it must suppress the discount even mid-window.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // internal/admin-facing
            $table->string('customer_title'); // shown to the customer
            $table->text('description')->nullable();
            $table->string('banner_image')->nullable();
            $table->string('type')->default('urgent_sale'); // free string like Banner's own placement/NotificationCampaign's type -- urgent_sale/festival_sale/upcoming_sale/stock_clearance/weekend_sale/last_minute_sale/launch_promotion/service_slot_clearance are the mission's own examples, not a closed enum
            $table->enum('status', ['draft', 'scheduled', 'live', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone')->default('UTC'); // display-only -- starts_at/ends_at are always stored/compared in UTC, same as every other timestamp column in this codebase; this is what a customer-facing "sale ends in..." label formats against, so an India-only default is never hardcoded here
            $table->enum('scope_type', ['global', 'country', 'city', 'zone', 'franchise'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->enum('discount_type', ['flat', 'percent'])->default('percent'); // same vocabulary Coupon already uses -- not a new discount taxonomy
            $table->decimal('discount_value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable(); // caps a percent discount's absolute amount, same as Coupon.max_discount
            $table->decimal('min_final_price', 10, 2)->default(0); // safety floor -- the discounted price can never go below this (and never below 0 regardless)
            $table->unsignedInteger('total_quantity_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('per_customer_limit')->nullable(); // null = unlimited
            $table->foreignId('badge_id')->nullable()->constrained('badges')->nullOnDelete(); // optional catalog-facing badge shown alongside the sale price (e.g. the seeded 'flash_sale' badge)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sales');
    }
};
