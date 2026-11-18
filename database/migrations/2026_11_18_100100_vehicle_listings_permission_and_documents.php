<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vehicles for rent (owner 2026-11-18, OLX-style): the team permission to approve vehicles and their changes
 * (`vehicle_listings.manage`, given to Super Admin and every role that already approves property for rent), and the
 * documents a vehicle owner uploads at sign-up (applicable_type 'vehicle'). Idempotent.
 */
return new class extends Migration
{
    private const DOCUMENTS = [
        ['id_proof', 'Owner photo ID (Aadhaar, PAN, passport or voter ID)', true],
        ['vehicle_rc', 'Vehicle RC (registration certificate)', true],
        ['vehicle_insurance', 'Vehicle insurance', false],
        ['bank_details', 'Bank details (cancelled cheque or passbook first page)', false],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::DOCUMENTS as $i => [$doc, $label, $required]) {
            if (! DB::table('kyc_document_requirements')->where('applicable_type', 'vehicle')->where('document_type', $doc)->whereNull('country_id')->exists()) {
                DB::table('kyc_document_requirements')->insert([
                    'applicable_type' => 'vehicle', 'document_type' => $doc, 'label' => $label, 'is_required' => $required,
                    'country_id' => null, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if (! DB::table('permissions')->where('slug', 'vehicle_listings.manage')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'vehicle_listings.manage', 'label' => 'Vehicles for rent: approve owner vehicles and their changes, feature or switch off a vehicle',
                'group' => 'Rental', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'vehicle_listings.manage')->value('id');
        $sourceId = DB::table('permissions')->where('slug', 'homes.manage')->value('id');
        $roleIds = DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id')
            ->push(DB::table('roles')->where('slug', 'super_admin')->value('id'))->filter()->unique();
        foreach ($roleIds as $roleId) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'vehicle_listings.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        DB::table('kyc_document_requirements')->where('applicable_type', 'vehicle')->delete();
    }
};
