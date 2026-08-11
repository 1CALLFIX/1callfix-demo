<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors provider_documents' exact shape (type/file_url/status/
// rejection_reason) — a dedicated Worker document table per the Phase B0
// audit's explicit choice (not a polymorphic widening of provider_documents
// in this phase). No new document types or KYC policy invented — that
// stays an open business decision.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_worker_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_worker_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('file_url');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_worker_documents');
    }
};
