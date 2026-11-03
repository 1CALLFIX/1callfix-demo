<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-03: "just take a photo and the platform reads the list". A seller's photo of a price list, bill,
 * shelf or menu board is read in the background (queue) and the items it found wait here for the seller to check
 * and add. The photo is deleted once it has been read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_photo_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('waiting')->index(); // waiting | reading | done | failed
            $table->json('photo_paths')->nullable(); // private disk, removed after reading
            $table->json('items')->nullable();       // what was read
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photo_scans');
    }
};
