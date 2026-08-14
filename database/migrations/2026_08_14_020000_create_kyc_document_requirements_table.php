<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Configurable required-document mechanism (mission Phase 2 explicit
// request: "Build a configurable document requirement mechanism where
// safe... Do NOT hard-code country-specific requirements without
// evidence"). Admin-editable via the KYC settings screen; the ENGINE only
// ever reads this table to decide what's required -- it never hard-codes
// a document list in PHP logic. country_id null = applies everywhere;
// set = overrides/adds for that country specifically.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->enum('applicable_type', ['provider', 'field_worker']);
            $table->string('document_type');
            $table->string('label');
            $table->boolean('is_required')->default(true);
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['applicable_type', 'document_type', 'country_id'], 'kyc_doc_req_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_document_requirements');
    }
};
