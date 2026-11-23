<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field associates (owner 2026-11-23): people the company hires to visit shops and restaurants and sign them up.
 * Each associate has a code (FA001); a shop signed up through their link or QR remembers who added it
 * (stores.field_associate_id). Rewards are written once per shop and kind ("shop live", "first order"), with the
 * amount at that time, so a later change of the amount never rewrites a month already paid.
 *
 * Additive only: two new tables, one nullable column, one grantable permission (Super Admin always holds it).
 */
return new class extends Migration
{
    private const PERMISSION = 'field_associates.manage';

    public function up(): void
    {
        Schema::create('field_associates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 12)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('field_associate_id')->nullable()->after('provider_id')->constrained('field_associates')->nullOnDelete();
        });

        Schema::create('field_associate_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_associate_id')->constrained('field_associates')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // shop_live | first_order
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('earned_at');
            $table->timestamps();
            $table->unique(['store_id', 'kind']);
            $table->index(['field_associate_id', 'earned_at']);
        });

        $now = now();
        if (! DB::table('permissions')->where('slug', self::PERMISSION)->exists()) {
            DB::table('permissions')->insert([
                'slug' => self::PERMISSION, 'label' => 'Field associates: add associates, see the shops they added and their rewards', 'group' => 'Marketplace',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        $permissionId = DB::table('permissions')->where('slug', self::PERMISSION)->value('id');
        if ($superAdminRoleId && $permissionId
            && ! DB::table('permission_role')->where('role_id', $superAdminRoleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('permission_role')->insert(['role_id' => $superAdminRoleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::PERMISSION)->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::dropIfExists('field_associate_rewards');
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_associate_id');
        });
        Schema::dropIfExists('field_associates');
    }
};
