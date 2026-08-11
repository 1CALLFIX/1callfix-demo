<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Partner <-> Worker relationship — a link table, not a column on
// either side (Phase B0 audit §17): provider_id does NOT go on
// field_workers, so a worker with zero rows here is still a fully valid
// platform-direct worker (required for future Parcel/Taxi), and the schema
// doesn't foreclose a worker eventually holding more than one active link
// if that's approved later (§32.1, explicitly not decided by this
// migration). No scheduling/availability-conflict logic here — out of
// scope for B0.1.
//
// status mirrors business_accounts.status's exact vocabulary
// (pending/active/suspended) rather than inventing a new one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_worker_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['provider_id', 'field_worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_workers');
    }
};
