<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Phase 22.1 (Module Activation Foundation). New screen, new permission —
// same single-.manage-permission, super-admin-only-by-default pattern as
// franchise_pricing.manage/chat.view before it. Geography-level module
// activation is a platform-shaping control (it decides what an entire
// country/city/zone/franchise can even see), so it starts locked down the
// same way every other genuinely new capability in this codebase has.
return new class extends Migration
{
    private const SLUG = 'modules.manage';
    private const LABEL = 'Manage module activation (country/city/zone/franchise)';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'slug' => self::SLUG, 'label' => self::LABEL, 'group' => 'Modules',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId, 'permission_id' => $permissionId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
