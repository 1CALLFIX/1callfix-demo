<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which entity carries which badge, in what geographic scope, for how long
// -- only ever written for 'manual'-mode badges (see badges table's own
// docblock; 'automatic' badges are computed live, never persisted here).
//
// badgeable_type/badgeable_id: polymorphic, same shape as Subscription's
// subscribable_type/id -- Service today (the catalog-facing badge examples
// this engine exists for: NEW/POPULAR/FEATURED/... service listings), any
// future entity without a schema change.
//
// scope_type/scope_id: the exact Plan/NotificationCampaign shape (global/
// country/city/zone/franchise + nullable scope_id) -- Services are global
// catalog rows (no franchise_id of their own, confirmed by reading
// Service's migration/model), so this is how "Service X is FEATURED only
// in Zone 5" is expressed without a duplicate Service row per franchise,
// the same reasoning Banner's own zone_id targeting already established.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->string('badgeable_type');
            $table->unsignedBigInteger('badgeable_id');
            $table->enum('scope_type', ['global', 'country', 'city', 'zone', 'franchise'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestamp('starts_at')->nullable(); // null = effective immediately
            $table->timestamp('expires_at')->nullable(); // null = no expiry
            $table->boolean('is_active')->default(true); // admin can deactivate without deleting the audit row
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['badgeable_type', 'badgeable_id']);
            $table->index(['scope_type', 'scope_id']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_assignments');
    }
};
