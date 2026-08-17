<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 24 (Marketplace Foundation). Direct match to 6amMart's real `add_ons` table -- simple, store-scoped, no addon-category sub-table (no evidence that extra tier is required to prove the architecture). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('add_ons');
    }
};
