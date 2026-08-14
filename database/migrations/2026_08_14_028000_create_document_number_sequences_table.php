<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Printing/Document Engine (mission Phase 7). One row per (type, country,
// year) — a per-country, per-year sequential counter, atomically
// incremented under a row lock (DocumentNumberService), the same
// "lockForUpdate + DB::transaction" convention every other sequential/
// financial counter in this codebase uses. Per-country rather than global
// so numbering stays legible per-country accounting practice without
// inventing a specific national numbering LAW (this is pure sequencing,
// not tax-compliance formatting).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['invoice', 'receipt']);
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['type', 'country_id', 'year'], 'doc_number_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};
