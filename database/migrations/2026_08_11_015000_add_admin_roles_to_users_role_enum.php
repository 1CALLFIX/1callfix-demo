<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds the admin-side actor types §8 of the corrected Control Plane rule
// asks for (Country Admin, City Admin, Operator, Support) to the existing
// users.role enum. franchise_owner and zone_manager already covered those
// two levels; super_admin/customer/provider already existed. This column
// stays the coarse "what kind of person is this" tag it always was —
// role_assignments (fine-grained, scope-aware permission grants) is layered
// on top of it, not a replacement for it.
return new class extends Migration
{
    private const OLD = ['customer', 'provider', 'franchise_owner', 'zone_manager', 'super_admin'];
    private const NEW = ['customer', 'provider', 'franchise_owner', 'zone_manager', 'country_admin', 'city_admin', 'operator', 'support', 'super_admin'];

    public function up(): void
    {
        // Was a raw `ALTER TABLE users MODIFY role ENUM(...)` — MySQL-only
        // syntax that made this migration (and therefore the whole chain)
        // impossible to run on sqlite, which the automated test suite uses
        // per phpunit.xml. Laravel's native change() (no doctrine/dbal
        // needed since Laravel 9+) compiles to the equivalent ALTER on
        // MySQL and to SQLite's own table-rebuild strategy on SQLite. Same
        // resulting column on MySQL; no production impact since this
        // migration has already run for real.
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::NEW)->default('customer')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::OLD)->default('customer')->change();
        });
    }
};
