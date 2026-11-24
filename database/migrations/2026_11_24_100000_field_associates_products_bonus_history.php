<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field associates, release A (owner 2026-11-24):
 *  - field_associate_rewards gains the first order's order and customer: a refunded order cancels its reward, and one
 *    customer can be the first order of only one of an associate's shops.
 *  - field_associate_bonuses: one row per associate and month, the highest bonus level reached (10 / 20 / 30 / 40 shops).
 *  - field_associate_credit_changes: who moved or removed a shop's credit, when and why (a reason is required).
 *  - The owner's incentive plan (option 2: fixed pay + petrol outside the app, double incentives) as the starting
 *    amounts, only where Super Admin has not set an amount yet: ₹50 live,
 *    ₹50 for 10 approved products, ₹100 first order (order of ₹100 or more), at most ₹200 per shop, bonus ₹500 / ₹1,000 /
 *    ₹1,500 / ₹2,500 at 10 / 20 / 30 / 40 shops in a month.
 *
 * Additive only: two new tables, two nullable columns, settings written only where empty or 0.
 */
return new class extends Migration
{
    private const PLAN = [
        'field_associates.reward_shop_live' => '50',
        'field_associates.reward_products' => '50',
        'field_associates.reward_first_order' => '100',
        'field_associates.max_per_shop' => '200',
        'field_associates.first_order_min' => '100',
        'field_associates.bonus_levels' => '[[10,500],[20,1000],[30,1500],[40,2500]]',
    ];

    public function up(): void
    {
        Schema::table('field_associate_rewards', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->after('kind');
            $table->unsignedBigInteger('customer_id')->nullable()->after('order_id');
            $table->index(['field_associate_id', 'customer_id']);
        });

        Schema::create('field_associate_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_associate_id')->constrained('field_associates')->cascadeOnDelete();
            $table->date('month'); // first day of the month
            $table->unsignedInteger('shops');       // shops counted when the level was reached
            $table->unsignedInteger('level_shops'); // 10, 20, 30, ...
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('earned_at');
            $table->timestamps();
            $table->unique(['field_associate_id', 'month']);
        });

        Schema::create('field_associate_credit_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_associate_id')->nullable()->constrained('field_associates')->nullOnDelete();
            $table->foreignId('to_associate_id')->nullable()->constrained('field_associates')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });

        $now = now();
        foreach (self::PLAN as $key => $value) {
            $row = DB::table('settings')->where('scope_type', 'global')->whereNull('scope_id')->where('key', $key)->first();
            if ($row === null) {
                DB::table('settings')->insert(['scope_type' => 'global', 'scope_id' => null, 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            } elseif (trim((string) $row->value) === '' || (float) $row->value === 0.0 && ! str_starts_with((string) $row->value, '[')) {
                DB::table('settings')->where('id', $row->id)->update(['value' => $value, 'updated_at' => $now]);
            }
            cache()->forget("setting:global::{$key}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_associate_credit_changes');
        Schema::dropIfExists('field_associate_bonuses');
        Schema::table('field_associate_rewards', function (Blueprint $table) {
            $table->dropIndex(['field_associate_id', 'customer_id']);
            $table->dropColumn(['order_id', 'customer_id']);
        });
        // The amounts stay: they are Super Admin settings now.
    }
};
