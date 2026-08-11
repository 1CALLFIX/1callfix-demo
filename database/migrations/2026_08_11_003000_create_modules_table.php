<?php

use App\Support\Modules as ModuleList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The vertical registry from the master doc's §15 — data, not a PHP constant.
// Seeded from App\Support\Modules::ALL so the two can't immediately drift;
// that class stays in place as the seed source and the shared dropdown
// label lookup Categories/Subcategories/Services already depend on.
//
// `is_active` here means "registered as a real platform vertical" — every
// row seeds true, since all 9 are genuinely on the roadmap. That is a
// different question from `franchise_modules`' per-franchise boolean
// columns, which ask "does *this* franchise sell it *right now*" (only
// `service` is true there today). Registry vs. operational toggle —
// deliberately two different tables asking two different questions.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // matches Modules::ALL keys
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0); // P1–P9 rollout order
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $sortOrder = 0;
        foreach (ModuleList::ALL as $code => $label) {
            DB::table('modules')->insert([
                'code' => $code,
                'name' => $label,
                'sort_order' => ++$sortOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
