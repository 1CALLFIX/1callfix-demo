<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Catalog Import capability (mission Phase 14 input) — the real,
 * queryable "Import Report" every commit produces: one row per import run,
 * with per-outcome counts for fast display plus a full per-row JSON detail
 * (row number, external_id, name, outcome, message) for real auditability.
 * Same shape precedent as payment_webhook_logs/scheduled_task_runs (Phase
 * 10) — a real record of what actually happened, not a fabricated summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type'); // categories|subcategories|services
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name')->nullable();
            $table->boolean('deactivate_missing')->default(false);
            $table->string('status')->default('completed'); // completed|failed
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('results')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_runs');
    }
};
