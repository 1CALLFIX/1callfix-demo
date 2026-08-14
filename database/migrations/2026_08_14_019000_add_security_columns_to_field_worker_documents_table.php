<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors 018000's provider_documents columns exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_worker_documents', function (Blueprint $table) {
            $table->string('file_url')->nullable()->change();
        });

        Schema::table('field_worker_documents', function (Blueprint $table) {
            $table->string('disk_path')->nullable()->after('file_url');
            $table->string('original_filename')->nullable()->after('disk_path');
            $table->string('mime_type')->nullable()->after('original_filename');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');

            $table->boolean('is_current')->default(true)->after('status');
            $table->foreignId('uploaded_by')->nullable()->after('is_current')->constrained('users')->nullOnDelete();
            $table->enum('upload_source', ['self', 'franchise_assisted', 'admin'])->default('self')->after('uploaded_by');
            $table->foreignId('franchise_staff_id')->nullable()->after('upload_source')->constrained('users')->nullOnDelete();

            $table->foreignId('reviewed_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->timestamp('expires_at')->nullable()->after('reviewed_at');

            $table->index(['field_worker_id', 'type', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::table('field_worker_documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropForeign(['franchise_staff_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'disk_path', 'original_filename', 'mime_type', 'size_bytes',
                'is_current', 'uploaded_by', 'upload_source', 'franchise_staff_id',
                'reviewed_by', 'reviewed_at', 'expires_at',
            ]);
        });
    }
};
