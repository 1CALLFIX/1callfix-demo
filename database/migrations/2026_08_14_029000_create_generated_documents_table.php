<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Audit trail + idempotent numbering: the SAME underlying record
// (currently always a Payment — booking/wallet-topup/plan-subscription,
// the three purposes that table already models) always gets the SAME
// document number on every regeneration, never a fresh number per
// download. Polymorphic so a future Payout/Booking-direct document type
// can reuse this table without a new one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->enum('type', ['invoice', 'receipt']);
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['documentable_type', 'documentable_id', 'type'], 'generated_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
