<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Document security overhaul (mission Phase 2). provider_documents already
// had NO unique constraint on (provider_id, type) -- a genuine, accidental
// strength: a resubmission has always been possible as a plain new INSERT
// without losing history. What was actually missing is everything else the
// mission calls out: a real private storage path (file_url was a free-text
// string an admin view rendered as a raw, unauthenticated <a href>), MIME/
// size metadata, WHO uploaded it and how (self vs franchise-assisted), WHO
// reviewed it and when, an expiry, and a way to tell which row is the
// CURRENT submission for its type vs superseded history.
return new class extends Migration
{
    public function up(): void
    {
        // file_url was NOT NULL from the original P1 schema — going forward
        // disk_path is the real, authoritative storage location and
        // file_url is legacy-only, so it must become optional.
        Schema::table('provider_documents', function (Blueprint $table) {
            $table->string('file_url')->nullable()->change();
        });

        Schema::table('provider_documents', function (Blueprint $table) {
            // The real private-disk-relative path (KycDocumentService always
            // writes here going forward). file_url stays for legacy/display
            // fallback only -- neither is ever rendered as a raw <a href>
            // again (see KycDocumentController).
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

            $table->index(['provider_id', 'type', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::table('provider_documents', function (Blueprint $table) {
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
