<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-commerce Phase 4 — shop commission control (owner decision 2026-10-24: commission varies by category, vendor,
 * franchise and city, set by Super Admin and franchise admins). One row = one platform-fee % for a combination of
 * shop / category / franchise / city (blank = any). The most specific matching row wins (MarketplaceCommissionResolver);
 * no row = the existing resolver (negotiated deal → franchise fee → global). Permission marketplace_commission.manage:
 * Super Admin always; franchise holders set rules for their own franchise only, within the Shop Settings bounds.
 */
return new class extends Migration
{
    private const SLUG = 'marketplace_commission.manage';

    public function up(): void
    {
        Schema::create('marketplace_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('platform_fee_percent', 5, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'marketplace_category_id', 'franchise_id', 'city_id'], 'mkt_commission_rule_unique');
        });

        $now = now();
        DB::table('permissions')->insert(['slug' => self::SLUG, 'label' => 'Set shop commission rules', 'group' => 'Marketplace', 'created_at' => $now, 'updated_at' => $now]);
        if ($superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id')) {
            DB::table('permission_role')->insert(['role_id' => $superAdminRoleId, 'permission_id' => DB::table('permissions')->where('slug', self::SLUG)->value('id'), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('slug', self::SLUG)->delete();
        Schema::dropIfExists('marketplace_commission_rules');
    }
};
