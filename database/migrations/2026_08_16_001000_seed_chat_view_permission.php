<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Phase 21 item TECH-4 (Option A only -- read-only admin chat viewer).
// Universal Chat (chat_messages/ChatService/ChatController, mission Phase
// 6) has never had ANY admin-facing permission at all -- unlike almost
// every other screen this backlog touched, which reused an
// already-seeded-but-unused slug, no chat.* permission has ever existed
// in this codebase (confirmed by direct search before writing this).
// Same additive, same super-admin-only-by-default reasoning as every
// prior permission round (e.g. 2026_08_14_001000's operations.view):
// real customer/partner/worker conversations are privacy-sensitive by
// nature, so visibility starts locked down, assignable to other roles via
// /admin/roles once a real business need (e.g. a dedicated support role)
// shows up. Deliberately ONE permission only -- no chat.moderate or any
// other slug; intervention/moderation was evaluated and explicitly NOT
// authorized for this pass (see KNOWN_RISKS_AND_DECISIONS.md item 15 and
// PHASE_21_RELEASE_CANDIDATE_BACKLOG.md TECH-4's own design audit).
return new class extends Migration
{
    private const SLUG = 'chat.view';
    private const LABEL = 'View booking chat conversations (read-only)';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'slug' => self::SLUG, 'label' => self::LABEL, 'group' => 'System',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
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
