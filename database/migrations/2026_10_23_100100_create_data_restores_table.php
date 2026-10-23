<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REF 1CF-BACKUP-RESTORE-001 (Layer 2): one row per "Restore one" comparison / restore on the
// Backup & Restore screen: what was compared, the result, who asked, the reason and the safety copy.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_restores', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id');
            $table->string('scope_label')->nullable();
            $table->string('backup_id');
            $table->timestamp('backup_at')->nullable();
            // preparing -> ready -> restoring -> done | failed
            $table->string('status', 20)->default('preparing');
            $table->json('summary')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('executed_by')->nullable()->constrained('users');
            $table->string('safety_copy_path')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_restores');
    }
};
