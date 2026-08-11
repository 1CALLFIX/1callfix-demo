<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the admin-side actor types §8 of the corrected Control Plane rule
// asks for (Country Admin, City Admin, Operator, Support) to the existing
// users.role enum. franchise_owner and zone_manager already covered those
// two levels; super_admin/customer/provider already existed. This column
// stays the coarse "what kind of person is this" tag it always was —
// role_assignments (fine-grained, scope-aware permission grants) is layered
// on top of it, not a replacement for it.
return new class extends Migration
{
    private const OLD = "ENUM('customer','provider','franchise_owner','zone_manager','super_admin')";
    private const NEW = "ENUM('customer','provider','franchise_owner','zone_manager','country_admin','city_admin','operator','support','super_admin')";

    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY role '.self::NEW." NOT NULL DEFAULT 'customer'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY role '.self::OLD." NOT NULL DEFAULT 'customer'");
    }
};
