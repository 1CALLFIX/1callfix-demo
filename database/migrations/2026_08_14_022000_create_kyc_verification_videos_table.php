<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Partner KYC verification video (resolved business decision -- required
// for Partner KYC per the mission brief). Provider-only: the mission's
// resolved decisions name "Partner" specifically, never Rider/Worker, for
// this requirement. Private storage, never a public profile video (see
// KycDocumentController -- same authorization-gated retrieval path as
// documents, not a separate public asset).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verification_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('disk_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // The one-time phrase/challenge the partner was asked to say on
            // camera, if the admin-configured policy uses one -- proves the
            // video is a live, current recording, not reused footage.
            $table->string('challenge_phrase')->nullable();
            $table->enum('status', ['submitted', 'approved', 'rejected'])->default('submitted');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('upload_source', ['self', 'franchise_assisted', 'admin'])->default('self');
            $table->foreignId('franchise_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verification_videos');
    }
};
