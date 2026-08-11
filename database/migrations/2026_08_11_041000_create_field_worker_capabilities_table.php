<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One FieldWorker row, N capability rows — deliberately NOT a single
// worker_type column, so one worker can structurally hold multiple
// verticals (e.g. service_technician + parcel_rider) without a second
// FieldWorker row. capability_type is a plain string (validated against
// App\Support\WorkerTypes), not a DB enum — same reasoning
// service_categories.module's migration already gave for avoiding enum
// rewrites on every new vertical. service_category_id is nullable: it
// identifies which Service category a service_technician/handyman
// capability applies to; non-Service capabilities (parcel_rider,
// taxi_driver, ...) leave it null.
//
// Whether a worker is operationally ALLOWED to use multiple capabilities
// at once is explicitly not decided here (Phase B0 audit §32.2) — this
// migration only ensures the schema doesn't foreclose it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_worker_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_worker_id')->constrained()->cascadeOnDelete();
            $table->string('capability_type');
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->timestamps();

            // Explicit short name -- the auto-generated one
            // (field_worker_capabilities_field_worker_id_capability_type_index)
            // computes to exactly 64 chars, right at MySQL's identifier
            // limit that broke a Phase A migration; naming it explicitly
            // avoids relying on that edge.
            $table->index(['field_worker_id', 'capability_type'], 'fwc_worker_capability_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_worker_capabilities');
    }
};
