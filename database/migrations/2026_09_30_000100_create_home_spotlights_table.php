<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REF 1CF-HOME-SPOTLIGHT-001 — admin-curated tiles for the home page collage.
// One row per numbered slot (1–6). Additive only; an empty table means the
// home page keeps its automatic collage exactly as before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_spotlights', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('position')->unique();
            $table->string('target_type', 16); // 'service' | 'category'
            $table->unsignedBigInteger('target_id');
            $table->string('badge', 24)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_spotlights');
    }
};
