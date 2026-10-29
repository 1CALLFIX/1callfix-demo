<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-commerce (2026-10-29): the shop (Commerce) is built — sellers, storefront, delivery, returns, commission,
 * compliance, coupons — so the Modules screen may now switch it on, the same step parcel got on 2026-10-23. It stays
 * INACTIVE everywhere until an admin activates it per country / city / zone / franchise (ModuleActivationService:
 * only `service` is on by default). Food, grocery and pharmacy stay unimplemented (owner: Phase 1 is Commerce only).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')->where('code', 'commerce')->update(['is_implemented' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')->where('code', 'commerce')->update(['is_implemented' => false, 'updated_at' => now()]);
    }
};
