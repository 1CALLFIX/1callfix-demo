<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Starting global (country_id = null) defaults -- every document type named
// is one the mission brief itself listed as an example for that actor, not
// invented here. "Where applicable" document types (business proof, tax
// documents, driving licence, vehicle documents) seed as NOT required by
// default -- an admin turns them on per-country/globally once the real
// business requirement is known, rather than this migration guessing which
// countries need them.
return new class extends Migration
{
    private const ROWS = [
        ['applicable_type' => 'provider', 'document_type' => 'id_proof', 'label' => 'Government ID proof', 'is_required' => true, 'sort_order' => 1],
        ['applicable_type' => 'provider', 'document_type' => 'address_proof', 'label' => 'Address proof', 'is_required' => true, 'sort_order' => 2],
        ['applicable_type' => 'provider', 'document_type' => 'bank_details', 'label' => 'Bank / settlement details', 'is_required' => true, 'sort_order' => 3],
        ['applicable_type' => 'provider', 'document_type' => 'business_proof', 'label' => 'Business registration proof', 'is_required' => false, 'sort_order' => 4],
        ['applicable_type' => 'provider', 'document_type' => 'tax_document', 'label' => 'Tax / GST document', 'is_required' => false, 'sort_order' => 5],
        ['applicable_type' => 'provider', 'document_type' => 'skill_certificate', 'label' => 'Skill certificate', 'is_required' => false, 'sort_order' => 6],
        ['applicable_type' => 'provider', 'document_type' => 'police_verification', 'label' => 'Police verification', 'is_required' => false, 'sort_order' => 7],

        ['applicable_type' => 'field_worker', 'document_type' => 'id_proof', 'label' => 'Government ID proof', 'is_required' => true, 'sort_order' => 1],
        ['applicable_type' => 'field_worker', 'document_type' => 'address_proof', 'label' => 'Address proof', 'is_required' => true, 'sort_order' => 2],
        ['applicable_type' => 'field_worker', 'document_type' => 'bank_details', 'label' => 'Bank / settlement details', 'is_required' => true, 'sort_order' => 3],
        ['applicable_type' => 'field_worker', 'document_type' => 'driving_licence', 'label' => 'Driving licence', 'is_required' => false, 'sort_order' => 4],
        ['applicable_type' => 'field_worker', 'document_type' => 'vehicle_document', 'label' => 'Vehicle registration document', 'is_required' => false, 'sort_order' => 5],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('kyc_document_requirements')->insert(
            collect(self::ROWS)->map(fn ($row) => array_merge($row, [
                'country_id' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]))->all()
        );
    }

    public function down(): void
    {
        DB::table('kyc_document_requirements')
            ->whereNull('country_id')
            ->whereIn('document_type', collect(self::ROWS)->pluck('document_type')->unique()->all())
            ->delete();
    }
};
