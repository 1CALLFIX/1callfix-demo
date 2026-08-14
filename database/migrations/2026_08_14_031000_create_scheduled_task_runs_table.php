<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Operations/Troubleshoot expansion (mission Phase 10, item 3 -- Scheduler/
// CRON). Laravel's own scheduler has no persisted "last run" history by
// default -- this table is populated by REAL onSuccess()/onFailure() hooks
// attached to every Schedule::command() entry in routes/console.php (see
// that file), not fabricated. Absence of a row for a command simply means
// it hasn't run since this table existed -- the UI says exactly that,
// never claims a false "healthy".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command');
            $table->enum('status', ['success', 'failure']);
            $table->text('output')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(['command', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
