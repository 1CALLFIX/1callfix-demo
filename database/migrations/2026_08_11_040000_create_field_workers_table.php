<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase B0.1 — the universal field-execution identity, distinct from
// Provider (the accountable business/individual a job is offered to — see
// Provider's own docblock). 1:1 with users, same shape/columns as
// providers' own universal columns (kyc_status, is_online, current_lat/lng,
// location_updated_at, rating_avg, jobs_completed, is_active) — deliberately
// NOT provider commission/business/pricing columns, which stay Provider-only.
//
// Not built on the dormant provider_type=company / parent_provider_id /
// technicians() scaffolding on `providers` (confirmed zero real consumers
// anywhere in app/ during the Phase B0 audit) — that stays untouched, this
// is a parallel table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('kyc_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->boolean('is_online')->default(false);
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('jobs_completed')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_workers');
    }
};
