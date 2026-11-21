<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Digital Marketing (owner 2026-11-21):
 *  - permissions `marketing.posts.manage` (Social posts) and `marketing.contacts.manage` (Contacts and invitations),
 *    given to Super Admin and every role that already manages banners (`banners.manage`);
 *  - a "Marketing" role (posts, contacts, banners, Live Visitors) for the City Operations & Marketing hire, with its
 *    test login in `php artisan test-accounts` (thumb rule: every module ships with a test login);
 *  - the "Invite to the new 1CallFix app" template in Notification Center (the owner edits and sends it).
 * Idempotent; down() removes exactly these.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'marketing.posts.manage' => 'Social posts: plan, write and share posts on Facebook, Instagram, Google Business, WhatsApp',
        'marketing.contacts.manage' => 'Contacts: import old customers and invite them to the app (email, WhatsApp)',
    ];

    // dashboard.view: staff sign-in always lands on the Dashboard (row-scoped to their city).
    private const ROLE_PERMISSIONS = ['marketing.posts.manage', 'marketing.contacts.manage', 'banners.manage', 'visitors.view', 'dashboard.view'];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $slug => $label) {
            if (! DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->insert(['slug' => $slug, 'label' => $label, 'group' => 'Marketing', 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        if (! DB::table('roles')->where('slug', 'marketing')->exists()) {
            DB::table('roles')->insert(['name' => 'Marketing', 'slug' => 'marketing', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now,
                'description' => 'Social posts, contacts and app invitations, banners and Live Visitors for their city.']);
        }

        $bannerId = DB::table('permissions')->where('slug', 'banners.manage')->value('id');
        $grantTo = DB::table('permission_role')->where('permission_id', $bannerId)->pluck('role_id')
            ->push(DB::table('roles')->where('slug', 'super_admin')->value('id'))->filter()->unique();
        $marketingRole = DB::table('roles')->where('slug', 'marketing')->value('id');

        $attach = function (int $roleId, string $slug) use ($now) {
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
            if ($permissionId && ! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        };
        foreach ($grantTo as $roleId) {
            foreach (array_keys(self::PERMISSIONS) as $slug) {
                $attach($roleId, $slug);
            }
        }
        foreach (self::ROLE_PERMISSIONS as $slug) {
            $attach($marketingRole, $slug);
        }

        if (! DB::table('notification_templates')->where('key', 'app_invite')->exists()) {
            $play = 'https://play.google.com/store/apps/details?id=com.call.customer';
            DB::table('notification_templates')->insert([
                'key' => 'app_invite',
                'name' => 'Invite to the new 1CallFix app',
                'title_template' => 'The new 1CallFix app is here 🎉',
                'body_template' => "Book trusted home services, send parcels, order food and shop from Nellore stores, all in one app.\n"
                    ."Download it free: {$play}\n\n"
                    ."కొత్త 1CallFix యాప్ వచ్చేసింది! హోమ్ సర్వీసులు, పార్సెల్, ఫుడ్, షాపింగ్ అన్నీ ఒకే యాప్‌లో. ఉచితంగా డౌన్‌లోడ్ చేసుకోండి: {$play}",
                'description' => 'App invitation for existing customers (owner 2026-11-21). Edit the words, then pick it in Compose and Send.',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'app_invite')->delete();
        $roleId = DB::table('roles')->where('slug', 'marketing')->value('id');
        if ($roleId && ! DB::table('role_assignments')->where('role_id', $roleId)->exists()) {
            DB::table('permission_role')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
