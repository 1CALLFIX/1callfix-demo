<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Coupon engine C1 (docs/COUPON_ENGINE_DESIGN.md §2.1) — additive only.
// status is a string (not an enum) so it can grow without an enum-widening migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('name')->default('');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('module', 30)->default('service');
            $table->decimal('total_budget', 12, 2)->nullable();
            $table->decimal('reserved_amount', 12, 2)->default(0);
            $table->decimal('confirmed_amount', 12, 2)->default(0);
            $table->unsignedInteger('usage_count_reserved')->default(0);
            $table->unsignedInteger('usage_count_confirmed')->default(0);
            $table->boolean('stackable_with_flash')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        DB::table('coupons')->where('is_active', true)->update(['status' => 'active']);
        DB::table('coupons')->where('is_active', false)->update(['status' => 'paused']);
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'name', 'description', 'status', 'module', 'total_budget', 'reserved_amount',
                'confirmed_amount', 'usage_count_reserved', 'usage_count_confirmed',
                'stackable_with_flash', 'created_by', 'updated_by',
            ]);
        });
    }
};
