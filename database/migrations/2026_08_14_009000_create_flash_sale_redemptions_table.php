<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per booking that actually used a flash sale price — the audit
// trail total_quantity_limit/per_customer_limit are enforced against (same
// "usages" pattern coupon_usages already established for Coupon), and the
// reconciliation record for original vs. discounted price.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('original_price', 10, 2);
            $table->decimal('final_price', 10, 2);
            $table->decimal('discount_applied', 10, 2);
            $table->timestamps();

            $table->index(['flash_sale_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_redemptions');
    }
};
