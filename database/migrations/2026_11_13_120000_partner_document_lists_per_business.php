<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partners part B (owner 2026-11-12: "every business ... should feel very easy to join"): each kind of business gets its
 * own document list instead of the service-provider one (a hotel was asked for a "skill certificate", a restaurant never
 * for its FSSAI licence). kyc_document_requirements.applicable_type was enum('provider','field_worker') on production
 * (read with SHOW COLUMNS on 2026-11-12, exactly these two values); it becomes a short string so new kinds need no
 * further schema change: provider (service), field_worker (rider), hotel, restaurant, shop.
 * Only the starting lists are written here; Super Admin edits them in Admin → Partner documents.
 */
return new class extends Migration
{
    private const LISTS = [
        'hotel' => [
            ['id_proof', 'Owner or manager photo ID (Aadhaar, PAN, passport or voter ID)', true],
            ['property_proof', 'Property proof (ownership papers or rent agreement)', true],
            ['bank_details', 'Bank details (cancelled cheque or passbook first page)', true],
            ['trade_licence', 'Trade licence or hotel licence', false],
            ['tax_document', 'GST certificate', false],
        ],
        'restaurant' => [
            ['id_proof', 'Owner photo ID (Aadhaar, PAN, passport or voter ID)', true],
            ['fssai_licence', 'FSSAI licence or registration', true],
            ['bank_details', 'Bank details (cancelled cheque or passbook first page)', true],
            ['tax_document', 'GST certificate', false],
            ['trade_licence', 'Trade licence', false],
        ],
        'shop' => [
            ['id_proof', 'Owner photo ID (Aadhaar, PAN, passport or voter ID)', true],
            ['bank_details', 'Bank details (cancelled cheque or passbook first page)', true],
            ['tax_document', 'GST certificate', false],
            ['business_proof', 'Shop licence or business registration', false],
        ],
    ];

    public function up(): void
    {
        Schema::table('kyc_document_requirements', function (Blueprint $table) {
            $table->string('applicable_type', 30)->change();
        });

        $now = now();
        foreach (self::LISTS as $type => $rows) {
            foreach ($rows as $i => [$doc, $label, $required]) {
                $exists = DB::table('kyc_document_requirements')->where('applicable_type', $type)->where('document_type', $doc)->whereNull('country_id')->exists();
                if (! $exists) {
                    DB::table('kyc_document_requirements')->insert([
                        'applicable_type' => $type, 'document_type' => $doc, 'label' => $label, 'is_required' => $required,
                        'country_id' => null, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('kyc_document_requirements')->whereIn('applicable_type', array_keys(self::LISTS))->delete();
        Schema::table('kyc_document_requirements', function (Blueprint $table) {
            $table->enum('applicable_type', ['provider', 'field_worker'])->change();
        });
    }
};
