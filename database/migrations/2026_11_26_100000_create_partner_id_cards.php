<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-26: a 1CallFix ID card for the people who go to customers (service partners and riders / drivers),
 * on the phone and as a PDF to print. One card per partner per app. The QR on it opens /verify/{token}, a live check
 * (photo, name, Active or Not active right now), so an old or copied card fails the scan. A new token (lost card,
 * renewal) makes the old QR invalid.
 *
 * The photo on the card is a KYC document the franchise approves, like the others: an optional "id_card_photo" row
 * on the service partner and rider document lists (Admin → Partner documents can still edit or switch it off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_id_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('holder_type', 20); // provider | rider
            $table->unsignedBigInteger('holder_id');
            $table->string('token', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('valid_until');
            // Owner 2026-11-26: printed on the back of the card only (never on the public verify page).
            $table->string('blood_group', 3)->nullable(); // A+ A- B+ B- AB+ AB- O+ O-
            $table->json('emergency_contacts')->nullable(); // [{relation, phone}] — one or two family numbers
            $table->timestamps();
            $table->unique(['holder_type', 'holder_id']);
        });

        foreach (['provider', 'field_worker'] as $type) {
            $exists = DB::table('kyc_document_requirements')
                ->where('applicable_type', $type)->where('document_type', 'id_card_photo')->whereNull('country_id')->exists();
            if (! $exists) {
                DB::table('kyc_document_requirements')->insert([
                    'applicable_type' => $type,
                    'document_type' => 'id_card_photo',
                    'label' => 'Your photo for the 1CallFix ID card (your face clearly, plain background)',
                    'is_required' => false,
                    'country_id' => null,
                    'sort_order' => 900,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_id_cards');
        DB::table('kyc_document_requirements')->where('document_type', 'id_card_photo')->delete();
    }
};
