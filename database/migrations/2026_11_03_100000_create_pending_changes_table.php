<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner rule 2026-11-03 (after comparing Amazon, Flipkart, Swiggy, Zomato): a big change to a live item or shop —
 * an item's name, photos, description or category; a shop's address, map pin, GSTIN or PAN — waits here for admin
 * approval while the item / shop stays live with its old details. Price, stock and on/off are never held here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_changes', function (Blueprint $table) {
            $table->id();
            $table->morphs('changeable');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('new_values');   // field => new value
            $table->json('old_values');  // field => value when asked
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected | replaced
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_changes');
    }
};
