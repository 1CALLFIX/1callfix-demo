<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// WHERE a role grants its permissions for a given user — deliberately reuses
// the exact scope vocabulary Setting::SCOPE_ORDER already established
// (global/country/city/zone/module/franchise), not a second scope system.
// One user can hold multiple assignments (e.g. Zone Admin for Zone A AND
// Support globally) — AuthorizationService checks all of them and grants
// access if ANY assignment's role has the permission AND its scope covers
// the request. Unlike Setting's cascade (most-specific WINS, others don't
// apply), permission grants are additive — more assignments only ever add
// access, never take it away.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->enum('scope_type', ['global', 'country', 'city', 'zone', 'module', 'franchise']);
            // Null only for scope_type = 'global'.
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'scope_type', 'scope_id'], 'role_assignments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
